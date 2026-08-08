/**
 * ID発行管理画面の補助スクリプト（子機URLの発行ダイアログとコピー）。
 * 画面本体はサーバーサイドレンダリングなので、ここでは操作の補助だけを行う。
 */
'use strict';

(() => {
  const dialog = document.getElementById('link-dialog');

  // 発行済みIDの「子機URL」ボタン → 表示名を入力するダイアログ
  document.querySelectorAll('[data-link-id]').forEach((button) => {
    button.addEventListener('click', () => {
      if (!dialog) return;
      document.getElementById('link-target').textContent = button.dataset.linkId;
      document.getElementById('link-doorbell-id').value = button.dataset.linkId;
      dialog.showModal();
      document.getElementById('link-name').focus();
    });
  });

  document.getElementById('link-cancel')?.addEventListener('click', () => dialog?.close());

  // URLのコピー（クリップボードが使えない環境では選択状態にする）
  document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
      const target = document.getElementById(button.dataset.copy);
      if (!target) return;

      const label = button.textContent;
      try {
        await navigator.clipboard.writeText(target.textContent.trim());
        button.textContent = 'コピーしました';
      } catch {
        // https 以外では clipboard API が使えないため、手動でコピーできるようにする
        const range = document.createRange();
        range.selectNodeContents(target);
        const selection = getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        button.textContent = 'Ctrl+C でコピー';
      }
      setTimeout(() => { button.textContent = label; }, 3000);
    });
  });
})();
