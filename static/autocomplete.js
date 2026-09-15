
(function () {
  'use strict';

  const API = '/api/v1/tags/autocomplete';
  let activeIndex = -1;
  let currentDropdown = null;
  let debounceTimer = null;

  function formatCount(n) {
    if (n >= 1000) return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
    return n;
  }

  function removeDropdown() {
    if (currentDropdown) {
      currentDropdown.remove();
      currentDropdown = null;
    }
    activeIndex = -1;
  }

  function buildDropdown(input, items) {
    removeDropdown();
    if (!items.length) return;

    const rect = input.getBoundingClientRect();
    const dropdown = document.createElement('ul');
    dropdown.className = 'ac-dropdown';
    dropdown.style.cssText = [
      'position:fixed',
      'z-index:9999',
      'left:' + rect.left + 'px',
      'top:' + (rect.bottom + 2) + 'px',
      'width:' + rect.width + 'px',
      'margin:0',
      'padding:0',
      'list-style:none',
      'background:var(--bg-color,#fff)',
      'border:1px solid var(--border-color,#ccc)',
      'border-radius:3px',
      'box-shadow:0 4px 12px rgba(0,0,0,.15)',
      'max-height:260px',
      'overflow-y:auto',
      'overflow-x:hidden',
    ].join(';');

    items.forEach(function (tag, i) {
      const li = document.createElement('li');
      li.style.cssText = [
        'display:flex',
        'justify-content:space-between',
        'align-items:center',
        'padding:6px 10px',
        'cursor:pointer',
        'font-size:13px',
        'color:var(--tag-general,#0073ff)',
      ].join(';');
      li.dataset.value = tag.name;

      const nameSpan = document.createElement('span');
      nameSpan.textContent = tag.name;

      const countSpan = document.createElement('span');
      countSpan.textContent = formatCount(tag.count);
      countSpan.style.cssText = 'color:var(--muted-text,#888);font-size:11px;flex-shrink:0;margin-left:8px';

      li.appendChild(nameSpan);
      li.appendChild(countSpan);

      li.addEventListener('mouseenter', function () {
        setActive(dropdown, i);
      });

      li.addEventListener('mousedown', function (e) {
        e.preventDefault();
        selectItem(input, tag.name);
      });

      dropdown.appendChild(li);
    });

    document.body.appendChild(dropdown);
    currentDropdown = dropdown;
    activeIndex = -1;
  }

  function setActive(dropdown, index) {
    const items = dropdown.querySelectorAll('li');
    items.forEach(function (li, i) {
      li.style.background = i === index
        ? 'var(--border-color,#e5e5e5)'
        : '';
    });
    activeIndex = index;
  }

  function getCurrentToken(value) {
    const parts = value.split(/\s+/);
    return parts[parts.length - 1] || '';
  }

  function replaceCurrentToken(value, newToken) {
    const parts = value.split(/\s+/).filter(Boolean);
    parts.pop();
    parts.push(newToken);
    return parts.join(' ') + ' ';
  }

  function selectItem(input, tagName) {
    input.value = replaceCurrentToken(input.value, tagName);
    removeDropdown();
    input.focus();
  }

  function fetchSuggestions(input, query) {
    fetch(API + '?q=' + encodeURIComponent(query))
      .then(function (r) { return r.json(); })
      .then(function (tags) { buildDropdown(input, tags); })
      .catch(function () { removeDropdown(); });
  }

  function attachAutocomplete(input) {
    if (input.dataset.acAttached) return;
    input.dataset.acAttached = '1';

    input.addEventListener('input', function () {
      clearTimeout(debounceTimer);
      const token = getCurrentToken(input.value);
      if (token.length < 1) { removeDropdown(); return; }
      debounceTimer = setTimeout(function () {
        fetchSuggestions(input, token);
      }, 120);
    });

    input.addEventListener('keydown', function (e) {
      if (!currentDropdown) return;
      const items = currentDropdown.querySelectorAll('li');

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        const next = Math.min(activeIndex + 1, items.length - 1);
        setActive(currentDropdown, next);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        const prev = Math.max(activeIndex - 1, -1);
        setActive(currentDropdown, prev);
      } else if (e.key === 'Enter' || e.key === 'Tab') {
        if (activeIndex >= 0 && items[activeIndex]) {
          e.preventDefault();
          selectItem(input, items[activeIndex].dataset.value);
        } else {
          removeDropdown();
        }
      } else if (e.key === 'Escape') {
        removeDropdown();
      }
    });

    input.addEventListener('blur', function () {
      setTimeout(removeDropdown, 150);
    });
  }

  function init() {
    document.querySelectorAll('input[name="q"], input[name="tags"]').forEach(attachAutocomplete);

    const observer = new MutationObserver(function () {
      document.querySelectorAll('input[name="q"], input[name="tags"]').forEach(attachAutocomplete);
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
