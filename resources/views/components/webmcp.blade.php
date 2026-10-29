{{-- Tools an AI agent in the visitor's own browser can use (WebMCP): read a Listen page as text,
     or open one. Only browsers with the WebMCP API see them; for everyone else this does nothing.
     The contact form describes itself the same way (its toolname attributes). --}}
@php
  $agentPages = [
    'home' => route('home'),
    'pricing' => route('pricing'),
    'voice_samples' => route('samples'),
    'arabic_dialects' => route('seo.arabic-dialects'),
    'english_accents' => route('seo.english-accents'),
    'developers' => route('developers'),
    'browser_extension' => route('browsers-extension.page'),
    'contact' => route('contact'),
    'sign_up' => route('register'),
  ];
@endphp
<script id="listen-webmcp">
(function () {
  var context = document.modelContext || navigator.modelContext;
  if (!context || typeof context.registerTool !== 'function') return;
  var pages = @json($agentPages);
  var names = Object.keys(pages);
  var text = function (value) { return { content: [{ type: 'text', text: value }] }; };
  var register = function (tool) {
    try {
      var done = context.registerTool(tool);
      if (done && typeof done.catch === 'function') done.catch(function () {});
    } catch (e) {}
  };

  register({
    name: 'listen_read_page',
    description: 'Read a Listen page as Markdown: the plans and their prices, the voice samples, the Arabic dialects, the English accents, the developer API guide and more. Without a page, reads the page open now.',
    inputSchema: {
      type: 'object',
      properties: { page: { type: 'string', enum: names, description: 'Which page to read.' } }
    },
    execute: function (input) {
      var url = input && pages[input.page] ? pages[input.page] : location.href.split('#')[0];
      return fetch(url, { headers: { Accept: 'text/markdown' }, credentials: 'same-origin' }).then(function (response) {
        if ((response.headers.get('Content-Type') || '').indexOf('text/markdown') !== 0) {
          return text('This page has no text version. Pages that do: ' + names.join(', ') + '.');
        }
        return response.text().then(text);
      });
    }
  });

  register({
    name: 'listen_open_page',
    description: 'Open a Listen page in this tab, such as the pricing page, sign up or the voice samples.',
    inputSchema: {
      type: 'object',
      properties: { page: { type: 'string', enum: names, description: 'Which page to open.' } },
      required: ['page']
    },
    execute: function (input) {
      var url = input && pages[input.page];
      if (!url) return text('Unknown page. Choose one of: ' + names.join(', ') + '.');
      setTimeout(function () { location.assign(url); }, 0);
      return text('Opening ' + url);
    }
  });
})();
</script>
