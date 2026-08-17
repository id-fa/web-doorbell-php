/**
 * 親機と子機のボイスチャット（WebRTC）。テスト版機能。
 *
 * app.js は IIFE の中に閉じているため、依存（api / 設定 / 再描画）を受け取る形にして
 * ここから直接 app.js の内部を触らない。app.js からは create() で組み立てて使う。
 *
 * 構成は固定:
 *   - 音声は双方向
 *   - 映像は子機 → 親機の片方向（voiceVideo が false なら映像なし）
 *   - offer を出すのは常に親機。子機は無人設置の想定なので確認なしで応じる
 */
'use strict';

window.DoorbellVoice = {
  /**
   * @param {object} deps
   * @param {(action: string, payload?: object) => Promise<object>} deps.api
   * @param {object} deps.cfg   ブートストラップの config
   * @param {() => void} deps.onChange  通話の状態が変わったので描画し直してほしい
   * @param {(state: object) => void} deps.onState  サーバーから受け取った画面状態を反映してほしい
   * @param {(message: string) => void} deps.onError
   */
  create(deps) {
    const { api, cfg } = deps;

    const voice = {
      // 'idle' | 'connecting' | 'talking'
      phase: 'idle',
      session: null,
      peerName: '',
      muted: false,
      hasRemoteVideo: false,
      // 子機が呼び出し時に確保したマイク/カメラ（着信時に権限を求め直せないため）
      armed: false,
      lastError: '',

      pc: null,
      localStream: null,
      remoteStream: null,
      pumpTimer: null,
      wakeLock: null,
      // シグナルは届いた順に1件ずつ処理する（offer の適用前に ICE を足すと失敗するため）
      queue: Promise.resolve(),
      // セッションIDが分かる前に出た自分の ICE を貯めておく
      pending: [],

      /** この端末とこの設置環境で通話が使えるか */
      available() {
        return Boolean(
          cfg.voiceCall
          // getUserMedia は https（と localhost）でしか動かない。
          // サーバー側の判定ではリバースプロキシ配下で誤ることがあるのでここで見る
          && window.isSecureContext
          && navigator.mediaDevices?.getUserMedia
          && typeof window.RTCPeerConnection === 'function',
        );
      },

      /** 通話中（接続待ちを含む）か */
      busy() {
        return voice.phase !== 'idle';
      },

      /** 自分のカメラ映像を送っているか（子機のプレビュー表示に使う） */
      hasLocalVideo() {
        return Boolean(voice.localStream?.getVideoTracks().length);
      },

      /**
       * マイクとカメラを先に確保する。
       *
       * 子機は無人設置で自動着信するため、着信してからでは権限を求められない。
       * 必ず「呼び出す」ボタンのようなユーザー操作の中から呼ぶこと。
       */
      async prime() {
        if (!voice.available() || voice.localStream) return voice.armed;

        try {
          voice.localStream = await navigator.mediaDevices.getUserMedia({
            audio: true,
            video: cfg.voiceVideo ? { facingMode: 'user' } : false,
          });
        } catch {
          // カメラが使えないだけかもしれないので、音声だけでもう一度試す
          try {
            voice.localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
          } catch {
            voice.armed = false;
            return false;
          }
        }

        voice.armed = true;
        attach('voice-local-preview', voice.localStream, true);
        return true;
      },

      /** 通話に使っていないマイク/カメラを解放する（インジケータを消すため） */
      disarm() {
        if (voice.busy() || !voice.localStream) return;
        stopStream();
        voice.armed = false;
        attach('voice-local-preview', null);
      },

      /** 通話を開始する（親機のみ） */
      async start(call) {
        if (!call || voice.busy()) return;

        voice.phase = 'connecting';
        voice.peerName = call.childName;
        voice.lastError = '';
        deps.onChange();

        try {
          voice.localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
          attach('voice-local-preview', null);

          const pc = newPeer();
          voice.localStream.getTracks().forEach((track) => pc.addTrack(track, voice.localStream));
          // 映像は受けるだけ。子機の offer 待ちにせず、こちらの offer に m-line を作っておく
          if (cfg.voiceVideo) pc.addTransceiver('video', { direction: 'recvonly' });

          await pc.setLocalDescription(await pc.createOffer());

          const data = await api('voice_start', {
            call_id: call.id,
            sdp: { type: pc.localDescription.type, sdp: pc.localDescription.sdp },
          });

          voice.session = data.session;
          flushPending();
          startPump();
          deps.onChange();
        } catch (err) {
          voice.lastError = err.message || '通話を開始できませんでした。';
          await teardown('start_failed');
          deps.onError(voice.lastError);
        }
      },

      /**
       * 通話のポーリングを回すかどうかを切り替える。
       *
       * 呼び出し中と通話中だけ回す。画面全体のポーリング（既定10秒）では
       * SDP と ICE の交換に遅すぎるため、通話のときだけ 1 秒間隔にする。
       */
      watch(enabled) {
        if (!voice.available()) return;
        if (enabled) startPump();
        else if (!voice.busy()) stopPump();
      },

      /** 自分の音声を切る／戻す */
      toggleMute() {
        voice.muted = !voice.muted;
        voice.localStream?.getAudioTracks().forEach((track) => { track.enabled = !voice.muted; });
        deps.onChange();
        return voice.muted;
      },

      /** 通話を終える */
      async hangup(reason = 'hangup') {
        const sessionId = voice.session?.id ?? 0;
        await teardown(reason);

        if (sessionId > 0) {
          try {
            const data = await api('voice_end', { session_id: sessionId, reason });
            deps.onState(data.state);
          } catch { /* 相手や期限切れで既に終わっている場合は何もしない */ }
        }
      },

      /** 通話開始からの経過秒 */
      elapsed(nowSec) {
        const from = voice.session?.connectedAt || 0;
        return from > 0 ? Math.max(0, nowSec - from) : 0;
      },

      /** 接続を諦めるまでの残り秒（接続待ちのあいだだけ意味を持つ） */
      remaining(nowSec) {
        return voice.session ? Math.max(0, voice.session.expiresAt - nowSec) : 0;
      },
    };

    /* ---------------------------------------------------------------- */
    /* ポーリング（シグナルの受け渡し）                                 */
    /* ---------------------------------------------------------------- */

    function startPump() {
      if (voice.pumpTimer) return;
      voice.pumpTimer = setInterval(pump, Math.max(1, cfg.voicePollInterval) * 1000);
      pump();
    }

    function stopPump() {
      clearInterval(voice.pumpTimer);
      voice.pumpTimer = null;
    }

    async function pump() {
      if (pump.running) return;
      pump.running = true;

      try {
        const data = await api('voice_poll', { session_id: voice.session?.id ?? 0 });
        const before = voice.session?.status ?? '';

        if (!data.session) {
          // 相手が切った、または期限切れで消えた。
          // セッションをまだ持っていないときは畳まない。親機は voice_start の応答より先に
          // ここへ来ることがあり（通話開始で呼び出し画面を描き直すため）、
          // 始めたばかりの通話をこれで切ってしまう
          if (voice.session) await teardown('lost');
          return;
        }

        voice.session = data.session;
        voice.peerName = data.session.peerName;

        if (data.session.status === 'ended') {
          await teardown(data.session.endReason || 'ended');
          return;
        }

        data.signals.forEach(enqueue);
        if (before !== data.session.status) deps.onChange();
      } catch (err) {
        // 画面全体のポーリングとは別扱いにする。通話が一時的に落ちただけで
        // 「接続できません」の全画面表示を出すと、呼び出し自体まで見えなくなる
        if (err.code === 'voice_missing' || err.code === 'voice_ended' || err.code === 'forbidden') {
          await teardown('ended');
        }
      } finally {
        pump.running = false;
      }
    }

    /** シグナルは必ず届いた順に1件ずつ適用する */
    function enqueue(signal) {
      voice.queue = voice.queue.then(() => handle(signal)).catch((err) => {
        voice.lastError = err.message || '通話の接続に失敗しました。';
      });
    }

    async function handle(signal) {
      if (signal.kind === 'offer') {
        await acceptOffer(signal.payload);
        return;
      }

      if (!voice.pc) return;

      if (signal.kind === 'answer') {
        await voice.pc.setRemoteDescription(signal.payload);
        return;
      }

      if (signal.kind === 'ice' && signal.payload?.candidate) {
        try {
          await voice.pc.addIceCandidate(signal.payload);
        } catch { /* 相手より先に届いた・既に不要になった候補は捨てる */ }
      }
    }

    /* ---------------------------------------------------------------- */
    /* 子機側の自動着信                                                 */
    /* ---------------------------------------------------------------- */

    async function acceptOffer(offer) {
      if (voice.pc) return;

      // 呼び出しボタンでマイクを確保できていなければ通話できない。
      // 着信のタイミングでは権限を求められない（ユーザー操作がないため）
      if (!voice.localStream) {
        voice.lastError = 'マイクを使用できないため通話できません。';
        await voice.hangup('denied');
        return;
      }

      voice.phase = 'connecting';
      deps.onChange();

      const pc = newPeer();
      await pc.setRemoteDescription(offer);

      // 親機の offer が作った m-line にこちらのトラックを載せる。
      // 先に addTrack すると m-line の並びがずれることがあるため、この順にする
      pc.getTransceivers().forEach((transceiver) => {
        const kind = transceiver.receiver.track?.kind;
        const track = voice.localStream.getTracks().find((t) => t.kind === kind);

        if (!track) {
          transceiver.direction = 'inactive';
          return;
        }

        transceiver.sender.replaceTrack(track);
        // 音声は双方向、映像は子機 → 親機の片方向
        transceiver.direction = kind === 'video' ? 'sendonly' : 'sendrecv';
      });

      await pc.setLocalDescription(await pc.createAnswer());
      await api('voice_signal', {
        session_id: voice.session.id,
        kind: 'answer',
        payload: { type: pc.localDescription.type, sdp: pc.localDescription.sdp },
      });

      flushPending();
    }

    /* ---------------------------------------------------------------- */
    /* RTCPeerConnection                                                */
    /* ---------------------------------------------------------------- */

    function newPeer() {
      // 同一LAN内なら iceServers が空でも host candidate 同士で繋がる。
      // Wi-Fi のクライアント分離が有効だと繋がらないので、その場合は設定で STUN を足す
      const pc = new RTCPeerConnection({ iceServers: cfg.voiceIceServers ?? [] });
      voice.pc = pc;
      voice.remoteStream = new MediaStream();

      pc.addEventListener('icecandidate', (event) => {
        if (!event.candidate) return;
        sendCandidate(event.candidate.toJSON());
      });

      pc.addEventListener('track', (event) => {
        voice.remoteStream.addTrack(event.track);
        voice.hasRemoteVideo = voice.remoteStream.getVideoTracks().length > 0;
        // 再生先は役割で決め打ちにする。届いたトラックで切り替えると、音声だけ先に
        // 届いた段階で audio に繋いだあと video にも繋いでしまい、同じ音が二重に鳴る
        attach(voice.session?.role === 'parent' ? 'voice-remote-video' : 'voice-remote-audio', voice.remoteStream);
        deps.onChange();
      });

      pc.addEventListener('connectionstatechange', () => {
        if (pc !== voice.pc) return;

        if (pc.connectionState === 'connected') {
          onConnected();
        } else if (pc.connectionState === 'failed') {
          voice.lastError = '通話を開始できませんでした（ネットワークの経路が見つかりません）。';
          voice.hangup('failed');
        }
      });

      return pc;
    }

    async function onConnected() {
      if (voice.phase === 'talking') return;

      voice.phase = 'talking';
      requestWakeLock();
      deps.onChange();

      // 呼び出しを決着させるのは親機だけ。ここまで来なければ呼び出しは応答待ちのまま残る
      if (voice.session?.role !== 'parent') return;

      try {
        const data = await api('voice_connected', { session_id: voice.session.id });
        voice.session = data.session;
        deps.onState(data.state);
      } catch (err) {
        voice.lastError = err.message || '';
        await voice.hangup('call_closed');
        deps.onError(voice.lastError);
      }
    }

    function sendCandidate(candidate) {
      if (!voice.session) {
        // voice_start の応答より先に候補が出ることがある
        voice.pending.push(candidate);
        return;
      }

      api('voice_signal', { session_id: voice.session.id, kind: 'ice', payload: candidate })
        .catch(() => { /* 通話が既に終わっていれば捨ててよい */ });
    }

    function flushPending() {
      const queued = voice.pending.splice(0);
      queued.forEach(sendCandidate);
    }

    /* ---------------------------------------------------------------- */
    /* 後片付け                                                         */
    /* ---------------------------------------------------------------- */

    async function teardown(reason) {
      stopPump();
      releaseWakeLock();

      if (voice.pc) {
        voice.pc.getSenders().forEach((sender) => sender.replaceTrack(null).catch(() => {}));
        voice.pc.close();
      }

      voice.pc = null;
      voice.remoteStream = null;
      voice.hasRemoteVideo = false;
      voice.session = null;
      voice.phase = 'idle';
      voice.muted = false;
      voice.pending = [];
      voice.queue = Promise.resolve();

      attach('voice-remote-video', null);
      attach('voice-remote-audio', null);
      attach('voice-local-preview', null);
      stopStream();
      voice.armed = false;

      if (reason === 'timeout' || reason === 'failed' || reason === 'start_failed') {
        voice.lastError ||= '通話を開始できませんでした。';
      } else if (reason === 'denied') {
        // 子機がマイクを確保できていなかった場合。親機にも理由を伝える
        voice.lastError ||= '子機側でマイクを使用できないため通話できませんでした。';
      } else if (reason === 'lost') {
        voice.lastError ||= '相手との接続が切れました。';
      } else if (reason === 'max_time') {
        voice.lastError ||= '通話時間の上限に達したため終了しました。';
      }

      deps.onChange();
    }

    function stopStream() {
      voice.localStream?.getTracks().forEach((track) => track.stop());
      voice.localStream = null;
    }

    function attach(id, stream, muted = false) {
      const el = document.getElementById(id);
      if (!el) return;

      el.srcObject = stream;
      el.muted = muted;
      if (!stream) return;

      // 子機は自動着信なので、再生がブラウザに止められることがある。
      // 呼び出しボタンを押した直後であれば通常は通る
      el.play?.().catch(() => {
        deps.onError('音声を再生できませんでした。画面をタップしてください。');
      });
    }

    /** 通話中に Android タブレットの画面が消えないようにする */
    function requestWakeLock() {
      if (voice.wakeLock || !navigator.wakeLock) return;
      navigator.wakeLock.request('screen')
        .then((lock) => { voice.wakeLock = lock; })
        .catch(() => { /* 取得できなくても通話自体は続けられる */ });
    }

    function releaseWakeLock() {
      voice.wakeLock?.release().catch(() => {});
      voice.wakeLock = null;
    }

    return voice;
  },
};
