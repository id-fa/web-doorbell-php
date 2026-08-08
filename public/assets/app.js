/**
 * 簡易ドアベルシステム クライアント。
 * ログイン → 親機/子機画面の描画とポーリングを担当する。
 */
'use strict';

(() => {
  const boot = JSON.parse(document.getElementById('bootstrap-data').textContent);
  const cfg = boot.config;
  const RESPONSES = boot.responses;
  const RESPONSE_LABELS = boot.responseLabels;

  const $ = (id) => document.getElementById(id);
  const STORE = {
    deviceKey: 'doorbell.deviceKey',
    lastId: 'doorbell.lastId',
    lastName: 'doorbell.lastName',
  };

  /* ------------------------------------------------------------------ */
  /* 端末キー（履歴を同じブラウザに紐づけるための識別子）               */
  /* ------------------------------------------------------------------ */

  function deviceKey() {
    let key = '';
    try {
      key = localStorage.getItem(STORE.deviceKey) || '';
    } catch { /* プライベートモード等では localStorage が使えない */ }

    if (!/^[0-9a-f]{16,64}$/.test(key)) {
      const bytes = new Uint8Array(16);
      crypto.getRandomValues(bytes);
      key = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
      store(STORE.deviceKey, key);
    }
    return key;
  }

  function store(name, value) {
    try {
      localStorage.setItem(name, value);
    } catch { /* 保存できなくても動作に支障はない */ }
  }

  function recall(name) {
    try {
      return localStorage.getItem(name) || '';
    } catch {
      return '';
    }
  }

  /* ------------------------------------------------------------------ */
  /* リングトーン（外部音源を使わず Web Audio API で生成）              */
  /* ------------------------------------------------------------------ */

  const Ringtone = {
    ctx: null,
    nodes: [],
    playing: false,

    /** ユーザー操作の中で呼び出して自動再生制限を解除する */
    unlock() {
      try {
        const Ctx = window.AudioContext || window['webkitAudioContext'];
        if (!Ctx) return;
        this.ctx ||= new Ctx();
        if (this.ctx.state === 'suspended') this.ctx.resume();
      } catch { /* 音が出せない環境でも本体機能は継続する */ }
    },

    /** ピンポン音を repeat 回繰り返す */
    play(repeat = cfg.ringtoneRepeat) {
      this.unlock();
      if (!this.ctx) return;
      this.stop();
      this.playing = true;

      const cycle = 1.6;
      const start = this.ctx.currentTime + 0.02;
      for (let i = 0; i < repeat; i++) {
        this.chime(start + i * cycle, 987.77);        // ピン (B5)
        this.chime(start + i * cycle + 0.55, 783.99); // ポン (G5)
      }

      const totalMs = repeat * cycle * 1000;
      this.endTimer = setTimeout(() => { this.playing = false; }, totalMs);

      if (canVibrate()) {
        const pattern = [];
        for (let i = 0; i < repeat; i++) pattern.push(400, 1200);
        try { navigator.vibrate(pattern); } catch { /* 非対応端末は無視 */ }
      }
    },

    /** 減衰する単音（基音＋倍音）を鳴らす */
    chime(at, freq) {
      const gain = this.ctx.createGain();
      gain.gain.setValueAtTime(0.0001, at);
      gain.gain.exponentialRampToValueAtTime(0.35, at + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, at + 1.1);
      gain.connect(this.ctx.destination);

      [[freq, 1], [freq * 2, 0.35], [freq * 3.01, 0.12]].forEach(([f, level]) => {
        const osc = this.ctx.createOscillator();
        const mix = this.ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(f, at);
        mix.gain.setValueAtTime(level, at);
        osc.connect(mix).connect(gain);
        osc.start(at);
        osc.stop(at + 1.2);
        this.nodes.push(osc);
      });
    },

    stop() {
      clearTimeout(this.endTimer);
      this.nodes.forEach((osc) => { try { osc.stop(); } catch { /* 停止済み */ } });
      this.nodes = [];
      this.playing = false;
      if (canVibrate()) { try { navigator.vibrate(0); } catch { /* 非対応端末は無視 */ } }
    },
  };

  /** バイブレーションが使えるか（未操作のページで呼ぶとブラウザに拒否されるため確認する） */
  function canVibrate() {
    return typeof navigator.vibrate === 'function' && navigator.userActivation?.hasBeenActive !== false;
  }

  /* ------------------------------------------------------------------ */
  /* 通信                                                               */
  /* ------------------------------------------------------------------ */

  async function api(action, payload = {}) {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': boot.csrfToken,
      },
      body: JSON.stringify({ action, ...payload }),
      cache: 'no-store',
    });

    let data;
    try {
      data = await res.json();
    } catch {
      throw Object.assign(new Error('サーバーから正しい応答がありませんでした。'), { code: 'bad_response' });
    }

    if (!data.ok) {
      throw Object.assign(new Error(data.message || 'エラーが発生しました。'), { code: data.code || 'error' });
    }
    return data;
  }

  /* ------------------------------------------------------------------ */
  /* アプリケーション状態                                               */
  /* ------------------------------------------------------------------ */

  const app = {
    role: null,
    state: null,
    serverOffset: 0,       // サーバー時刻 - クライアント時刻（ミリ秒）
    pollTimer: null,
    tickTimer: null,
    knownCalls: new Map(), // 親機：検知済みの呼び出し（ID => 呼び出し回数）
    parentNotice: '',      // 親機：待ち受け画面に出す補足メッセージ
    cards: new Map(),      // 親機：複数呼び出し表示中のカード（ID => 要素）
    dismissedCallId: 0,    // 子機：「TOPに戻る」で閉じた呼び出しID
    offlineSince: null,
  };

  const nowSec = () => Math.floor((Date.now() + app.serverOffset) / 1000);

  function showScreen(name) {
    ['login', 'parent', 'child'].forEach((key) => {
      $(`screen-${key}`).hidden = key !== name;
    });
  }

  function show(el, visible) {
    el.hidden = !visible;
  }

  function showError(message) {
    const el = $('global-error');
    el.textContent = message;
    el.hidden = !message;
    clearTimeout(showError.timer);
    if (message) showError.timer = setTimeout(() => { el.hidden = true; }, 6000);
  }

  /* ------------------------------------------------------------------ */
  /* ログイン                                                           */
  /* ------------------------------------------------------------------ */

  $('login-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    Ringtone.unlock(); // ユーザー操作のうちに音声を有効化しておく

    const button = $('login-submit');
    const errorEl = $('login-error');
    const doorbellId = $('input-id').value.trim();
    const password = $('input-password').value;
    const displayName = $('input-name').value.trim();

    errorEl.hidden = true;
    button.disabled = true;
    button.textContent = 'ログイン中…';

    try {
      const data = await api('login', {
        doorbell_id: doorbellId,
        password,
        display_name: displayName,
        device_key: deviceKey(),
      });

      if (data.deviceKey) store(STORE.deviceKey, data.deviceKey);
      store(STORE.lastId, doorbellId);
      store(STORE.lastName, displayName);

      $('input-password').value = '';
      app.dismissedCallId = 0;
      app.knownCalls.clear();
      app.parentNotice = '';
      resetCards();

      applyState(data.state);
      startPolling();
    } catch (err) {
      errorEl.textContent = err.message;
      errorEl.hidden = false;
    } finally {
      button.disabled = false;
      button.textContent = 'ログイン';
    }
  });

  document.querySelectorAll('[data-action="logout"]').forEach((button) => {
    button.addEventListener('click', async () => {
      stopPolling();
      Ringtone.stop();
      try { await api('logout'); } catch { /* 失敗してもログイン画面へ戻す */ }
      toLogin();
    });
  });

  function toLogin(message = '') {
    stopPolling();
    Ringtone.stop();
    show($('disconnected'), false);
    app.offlineSince = null;
    app.role = null;
    app.state = null;
    $('input-id').value = recall(STORE.lastId);
    $('input-name').value = recall(STORE.lastName);
    const errorEl = $('login-error');
    errorEl.textContent = message;
    errorEl.hidden = !message;
    showScreen('login');
  }

  /* ------------------------------------------------------------------ */
  /* ポーリング                                                         */
  /* ------------------------------------------------------------------ */

  function startPolling() {
    stopPolling();
    app.pollTimer = setInterval(poll, cfg.pollingInterval * 1000);
    app.tickTimer = setInterval(tick, 500);
  }

  function stopPolling() {
    clearInterval(app.pollTimer);
    clearInterval(app.tickTimer);
    app.pollTimer = null;
    app.tickTimer = null;
  }

  async function poll() {
    if (poll.running) return;
    poll.running = true;
    try {
      const data = await api('state');
      app.offlineSince = null;
      showError('');
      applyState(data.state);
    } catch (err) {
      if (err.code === 'unauthenticated') {
        toLogin('セッションが切れました。もう一度ログインしてください。');
      } else if (err.code === 'csrf') {
        location.reload();
      } else {
        handleOffline();
      }
    } finally {
      poll.running = false;
    }
  }

  /**
   * 通信できないときの処理。
   * 状態表示が古いまま動き続けるのは危険なので、一定時間を過ぎたらポーリングを止めて明示する。
   */
  function handleOffline() {
    app.offlineSince ||= Date.now();
    const elapsed = Date.now() - app.offlineSince;

    if (elapsed >= cfg.offlineStopSeconds * 1000) {
      stopPolling();
      Ringtone.stop();
      showError('');
      $('disconnected-detail').textContent =
        `${Math.round(elapsed / 60000)} 分間、サーバーに接続できませんでした。`;
      show($('disconnected'), true);
      document.title = '⚠️ 接続できません';
      return;
    }

    showError(`サーバーに接続できません（${formatElapsed(elapsed)}）。再試行しています…`);
  }

  function formatElapsed(ms) {
    const seconds = Math.round(ms / 1000);
    return seconds < 60 ? `${seconds}秒` : `${Math.floor(seconds / 60)}分${seconds % 60}秒`;
  }

  $('reconnect').addEventListener('click', async () => {
    show($('disconnected'), false);
    Ringtone.unlock(); // ユーザー操作のうちに音声を有効化し直す
    app.offlineSince = null;
    startPolling();
    await poll();
  });

  function applyState(state) {
    app.serverOffset = state.serverTime * 1000 - Date.now();
    app.state = state;
    app.role = state.role;

    if (state.role === 'parent') {
      showScreen('parent');
      renderParent(state);
    } else {
      showScreen('child');
      renderChild(state);
    }
    tick();
  }

  /** 1秒未満の間隔でカウントダウン等を更新する */
  function tick() {
    if (!app.state) return;
    if (app.role === 'parent') tickParent();
    else tickChild();
  }

  /* ------------------------------------------------------------------ */
  /* 親機                                                               */
  /* ------------------------------------------------------------------ */

  const cardsRoot = $('parent-call-cards');

  // 呼び出しが1件のときの応答ボタン（メッセージ全文を表示する）
  const parentResponses = $('parent-responses');
  Object.entries(RESPONSES).forEach(([key, message]) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-response';
    button.textContent = message;
    button.addEventListener('click', () => {
      respond((app.state?.activeCalls ?? [])[0], key, message, parentResponses);
    });
    parentResponses.appendChild(button);
  });

  function showParentStage(name) {
    ['idle', 'calling', 'multi', 'answered'].forEach((key) => {
      show($(`parent-${key}`), key === name);
    });
  }

  /** 他の呼び出しを隠さずに送信結果を知らせる */
  function showToast(message) {
    const el = $('parent-toast');
    el.textContent = message;
    el.hidden = false;
    clearTimeout(showToast.timer);
    showToast.timer = setTimeout(() => { el.hidden = true; }, 6000);
  }

  /**
   * 応答を送信する。
   * @param {HTMLElement} container 送信中に無効化するボタンを含む要素
   */
  async function respond(call, key, message, container) {
    if (!call) return;

    Ringtone.stop();
    const buttons = [...container.querySelectorAll('button')];
    buttons.forEach((b) => { b.disabled = true; });

    try {
      const data = await api('respond', { call_id: call.id, response: key });
      app.knownCalls.delete(call.id);
      app.parentNotice = '';

      if (data.state.activeCalls.length === 0) {
        // 他に呼び出しがなければ、送信内容を全画面で確認できるようにする
        app.state = data.state;
        app.serverOffset = data.state.serverTime * 1000 - Date.now();
        resetCards();
        $('parent-answered-message').textContent = `「${message}」を送信しました`;
        showParentStage('answered');
        setTimeout(() => {
          if (app.role === 'parent' && (app.state?.activeCalls ?? []).length === 0) renderParent(app.state);
        }, 5000);
      } else {
        // 待っている呼び出しが残っているので、結果は上部に短時間だけ表示する
        showToast(`${call.childName} に「${message}」を送信しました`);
        applyState(data.state);
      }
    } catch (err) {
      showError(err.message);
      if (err.code === 'call_closed') poll();
    } finally {
      buttons.forEach((b) => { b.disabled = false; });
    }
  }

  function renderParent(state) {
    $('parent-name').textContent = state.displayName;
    const calls = state.activeCalls ?? [];

    // 新しい呼び出し、または応答待ち中の再呼び出しがあれば鳴らす（複数同時でも1回だけ）
    const hasNew = calls.some((call) => (app.knownCalls.get(call.id) ?? -1) < call.callCount);
    // 応答しないまま終了した呼び出し（応答済みのものは respond() で除外済み）
    const expired = [...app.knownCalls.keys()].some((id) => !calls.some((call) => call.id === id));

    app.knownCalls = new Map(calls.map((call) => [call.id, call.callCount]));
    if (hasNew) {
      Ringtone.play();
      app.parentNotice = '';
    } else if (expired) {
      app.parentNotice = '応答がないまま終了した呼び出しがあります（履歴を確認してください）';
    }

    if (calls.length === 0) {
      Ringtone.stop();
      resetCards();
      $('parent-idle-sub').textContent = app.parentNotice || '呼び出しをお待ちしています';
      renderChildren(state.children);
      renderHistory($('parent-history'), $('parent-history-empty'), state.history, true);
      showParentStage('idle');
      document.title = 'ドアベル（親機）';
      return;
    }

    if (calls.length === 1) {
      resetCards();
      const call = calls[0];
      $('parent-caller').textContent = call.childName;
      const repeatEl = $('parent-repeat');
      repeatEl.textContent = `呼び出し ${call.callCount} 回`;
      show(repeatEl, call.callCount > 1);
      showParentStage('calling');
      document.title = `🔔 呼び出し中 - ${call.childName}`;
      return;
    }

    // 2件以上は残り時間の短い順（＝古い順）にカードを並べる
    renderCallCards(calls);
    $('parent-multi-count').textContent = `呼び出し ${calls.length} 件`;
    showParentStage('multi');
    document.title = `🔔 呼び出し ${calls.length} 件`;
  }

  /**
   * 子機の稼働状態を表示する。
   * ログアウトした子機は一覧から消え、通信が途絶えただけの子機は「オフライン」として残るため、
   * ネットワークが切れたまま気づかない状況を発見できる。
   */
  function renderChildren(children = []) {
    const list = $('parent-children');
    list.textContent = '';
    show($('parent-children-title'), children.length > 0);
    show($('parent-children-empty'), children.length === 0);

    children.forEach((child) => {
      const item = document.createElement('li');
      item.className = child.online ? 'device is-online' : 'device is-offline';

      const dot = document.createElement('span');
      dot.className = 'device-dot';
      dot.setAttribute('aria-hidden', 'true');

      const name = document.createElement('span');
      name.className = 'device-name';
      name.textContent = child.name;

      const status = document.createElement('span');
      status.className = 'device-status';
      status.textContent = child.online
        ? 'オンライン'
        : `オフライン（最終応答 ${formatTime(child.lastSeenAt)}）`;

      item.append(dot, name, status);
      list.appendChild(item);
    });
  }

  function resetCards() {
    if (app.cards.size === 0) return;
    app.cards.clear();
    cardsRoot.textContent = '';
  }

  /**
   * 呼び出しカードを差分更新する。
   * 応答ボタンを押そうとしている最中に位置がずれないよう、既存のカードは作り直さず、
   * 新しい呼び出しは常に末尾へ追加する。
   */
  function renderCallCards(calls) {
    const alive = new Set();

    calls.forEach((call) => {
      alive.add(call.id);
      let card = app.cards.get(call.id);
      if (!card) {
        card = createCallCard(call);
        app.cards.set(call.id, card);
        cardsRoot.appendChild(card.root);
      }
      card.call = call;
      card.name.textContent = call.childName;
      card.count.textContent = `呼び出し ${call.callCount} 回`;
      show(card.count, call.callCount > 1);
    });

    app.cards.forEach((card, id) => {
      if (!alive.has(id)) {
        card.root.remove();
        app.cards.delete(id);
      }
    });
  }

  function createCallCard(call) {
    const root = document.createElement('div');
    root.className = 'call-card';

    const head = document.createElement('div');
    head.className = 'call-card-head';

    const labels = document.createElement('div');
    const name = document.createElement('p');
    name.className = 'call-card-name';
    const count = document.createElement('p');
    count.className = 'call-card-count';
    labels.append(name, count);

    const remaining = document.createElement('p');
    remaining.className = 'call-card-remaining';
    const seconds = document.createElement('strong');
    seconds.textContent = '--';
    remaining.append('残り ', seconds, ' 秒');

    head.append(labels, remaining);

    const actions = document.createElement('div');
    actions.className = 'call-card-actions';

    const card = { root, name, count, seconds, call };
    Object.entries(RESPONSE_LABELS).forEach(([key, label]) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn';
      button.textContent = label;
      button.title = RESPONSES[key];
      button.addEventListener('click', () => respond(card.call, key, RESPONSES[key], actions));
      actions.appendChild(button);
    });

    root.append(head, actions);
    return card;
  }

  function tickParent() {
    const calls = app.state?.activeCalls ?? [];
    if (calls.length === 0) return;

    if (!$('parent-calling').hidden) {
      const remaining = Math.max(0, calls[0].expiresAt - nowSec());
      $('parent-countdown').textContent = String(remaining);
      if (remaining === 0) Ringtone.stop();
      return;
    }

    if (!$('parent-multi').hidden) {
      let pending = false;
      app.cards.forEach((card) => {
        const remaining = Math.max(0, card.call.expiresAt - nowSec());
        card.seconds.textContent = String(remaining);
        card.root.classList.toggle('is-urgent', remaining > 0 && remaining <= 10);
        if (remaining > 0) pending = true;
      });
      if (!pending) Ringtone.stop();
    }
  }

  /* ------------------------------------------------------------------ */
  /* 子機                                                               */
  /* ------------------------------------------------------------------ */

  $('call-button').addEventListener('click', async () => {
    const button = $('call-button');
    button.disabled = true;
    Ringtone.play();

    try {
      const data = await api('call');
      app.dismissedCallId = 0;
      applyState(data.state);
    } catch (err) {
      Ringtone.stop();
      showError(err.message);
      if (err.code === 'unauthenticated') toLogin('セッションが切れました。もう一度ログインしてください。');
    } finally {
      button.disabled = false;
    }
  });

  $('child-back').addEventListener('click', () => {
    const call = app.state?.currentCall;
    if (call) app.dismissedCallId = call.id;
    renderChild(app.state);
  });

  function renderChild(state) {
    $('child-name').textContent = state.displayName;
    show($('child-parent-offline'), !state.parentOnline);

    const call = state.currentCall;
    const closed = !call || call.id === app.dismissedCallId;

    if (closed) {
      Ringtone.stop();
      renderHistory($('child-history'), $('child-history-empty'), state.history, false);
      show($('child-waiting'), false);
      show($('child-answered'), false);
      show($('child-idle'), true);
      document.title = 'ドアベル（子機）';
      return;
    }

    if (call.status === 'waiting') {
      $('child-waiting-sub').textContent = call.callCount > 1
        ? `応答をお待ちください（呼び出し ${call.callCount} 回）`
        : '応答をお待ちください';
      show($('child-idle'), false);
      show($('child-answered'), false);
      show($('child-waiting'), true);
      document.title = '🔔 呼び出し中';
      return;
    }

    // 応答あり（親機の応答、または不在判定）
    Ringtone.stop();
    const answered = call.status === 'answered';
    $('child-responder').textContent = answered ? `${call.responder} からの応答` : '';
    show($('child-responder'), answered);
    $('child-answer-message').textContent = call.message || '';
    $('child-answer-message').classList.toggle('is-negative', !answered);
    show($('child-idle'), false);
    show($('child-waiting'), false);
    show($('child-answered'), true);
    document.title = 'ドアベル（子機）';
  }

  function tickChild() {
    const call = app.state?.currentCall;
    if (!call || call.id === app.dismissedCallId) return;

    if (call.status === 'waiting' && !$('child-waiting').hidden) {
      $('child-countdown').textContent = String(Math.max(0, call.expiresAt - nowSec()));
      return;
    }

    if (call.respondedAt && !$('child-answered').hidden) {
      const remaining = call.respondedAt + cfg.responseViewTimeout - nowSec();
      if (remaining <= 0) {
        app.dismissedCallId = call.id;
        renderChild(app.state);
        return;
      }
      $('child-auto-return').textContent = `${remaining} 秒後に自動でコール画面に戻ります`;
    }
  }

  /**
   * 応答履歴を描画する。
   * @param {boolean} withChildName 親機の履歴では呼び出し元の子機名も表示する
   */
  function renderHistory(listEl, emptyEl, history, withChildName) {
    listEl.textContent = '';
    show(emptyEl, !history || history.length === 0);
    if (!history) return;

    history.forEach((group) => {
      const item = document.createElement('li');
      item.className = `history-item history-${group.status}`;

      const head = document.createElement('div');
      head.className = 'history-head';

      const time = document.createElement('time');
      time.className = 'history-time';
      time.textContent = formatRange(group);
      head.appendChild(time);

      if (group.count > 1 || group.times > 1) {
        const badge = document.createElement('span');
        badge.className = 'history-badge';
        badge.textContent = `呼び出し ${group.count} 回`;
        head.appendChild(badge);
      }
      item.appendChild(head);

      if (withChildName) {
        const child = document.createElement('p');
        child.className = 'history-child';
        child.textContent = group.childName;
        item.appendChild(child);
      }

      const message = document.createElement('p');
      message.className = 'history-message';
      if (group.status !== 'answered') {
        message.textContent = group.message || boot.noAnswerMessage;
      } else {
        message.textContent = withChildName
          ? `${group.message}（${group.responder}）`
          : `${group.responder}：${group.message}`;
      }
      item.appendChild(message);

      listEl.appendChild(item);
    });
  }

  function formatRange(group) {
    const first = formatTime(group.firstAt);
    const last = formatTime(group.lastAt);
    return first === last ? last : `${first} 〜 ${last}`;
  }

  function formatTime(epochSeconds) {
    const date = new Date(epochSeconds * 1000);
    const today = new Date();
    const sameDay = date.toDateString() === today.toDateString();
    const time = `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
    return sameDay ? time : `${date.getMonth() + 1}/${date.getDate()} ${time}`;
  }

  /* ------------------------------------------------------------------ */
  /* 起動                                                               */
  /* ------------------------------------------------------------------ */

  // タブが再表示されたときは待たずに最新状態を取り込む
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && app.pollTimer) poll();
  });

  (async () => {
    if (boot.loggedIn) {
      try {
        const data = await api('state');
        applyState(data.state);
        startPolling();
        return;
      } catch { /* セッション切れ時はログイン画面へ */ }
    }
    toLogin();
  })();
})();
