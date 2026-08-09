/**
 * ID発行管理画面の補助スクリプト（URL・トークンのコピー）。
 * 画面本体はサーバーサイドレンダリングなので、ここでは操作の補助だけを行う。
 */
'use strict';

(() => {
  // クリップボードが使えない環境では、対象を選択状態にして手動コピーできるようにする
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
