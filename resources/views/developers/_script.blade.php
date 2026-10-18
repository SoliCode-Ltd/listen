<script>
  (function () {
    // Code tabs: show one language at a time in each block.
    document.querySelectorAll('[data-code-block]').forEach(function (block) {
      var tabs = block.querySelectorAll('.dev-tab');
      tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
          tabs.forEach(function (other) {
            other.setAttribute('aria-selected', other === tab ? 'true' : 'false');
            var panel = document.getElementById(other.getAttribute('data-tab'));
            if (panel) { panel.hidden = other !== tab; }
          });
        });
      });
    });

    // Copy buttons: the visible code of their block, or the target named by data-copy-target.
    document.querySelectorAll('[data-copy]').forEach(function (button) {
      button.addEventListener('click', function () {
        var text = '';
        var targetId = button.getAttribute('data-copy-target');
        if (targetId) {
          var target = document.getElementById(targetId);
          text = target ? (target.value || target.textContent || '') : '';
        } else {
          var block = button.closest('[data-code-block]');
          var visible = block ? block.querySelector('.dev-code:not([hidden])') : null;
          text = visible ? visible.textContent : '';
        }
        if (!text || !navigator.clipboard) { return; }
        navigator.clipboard.writeText(text.trim()).then(function () {
          var label = button.textContent;
          button.textContent = button.getAttribute('data-copied-label') || label;
          setTimeout(function () { button.textContent = label; }, 1600);
        });
      });
    });
  })();
</script>
