(function () {
  'use strict';

  const API = '/api/v1/tags/explanation';
  let dialog;
  let title;
  let text;
  let source;

  function ensureDialog() {
    if (dialog) return dialog;

    dialog = document.createElement('dialog');
    dialog.className = 'tag-explanation-dialog';
    dialog.innerHTML = [
      '<div class="tag-explanation-content">',
      '  <div class="tag-explanation-header">',
      '    <h3></h3>',
      '    <button type="button" class="tag-explanation-close" aria-label="Close">×</button>',
      '  </div>',
      '  <p class="tag-explanation-text"></p>',
      '  <p class="tag-explanation-source" hidden>Librebooru tag guide.</p>',
      '</div>'
    ].join('');
    document.body.appendChild(dialog);

    title = dialog.querySelector('h3');
    text = dialog.querySelector('.tag-explanation-text');
    source = dialog.querySelector('.tag-explanation-source');
    dialog.querySelector('.tag-explanation-close').addEventListener('click', function () {
      dialog.close();
    });
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) dialog.close();
    });
    return dialog;
  }

  function openExplanation(tag, button) {
    const modal = ensureDialog();
    title.textContent = tag;
    text.textContent = 'Loading explanation…';
    source.hidden = true;
    modal.showModal();
    button.disabled = true;

    fetch(API + '?tag=' + encodeURIComponent(tag))
      .then(function (response) {
        if (!response.ok) throw new Error('Request failed');
        return response.json();
      })
      .then(function (result) {
        title.textContent = result.tag || tag;
        text.textContent = result.description || 'No explanation is available for this tag.';
        source.hidden = result.source !== 'local';
      })
      .catch(function () {
        text.textContent = 'The explanation could not be loaded. Please try again.';
      })
      .finally(function () {
        button.disabled = false;
      });
  }

  document.addEventListener('click', function (event) {
    const button = event.target.closest('.tag-help');
    if (!button) return;
    event.preventDefault();
    event.stopPropagation();
    openExplanation(button.dataset.tag || '', button);
  });
})();
