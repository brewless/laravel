(() => {
  // WebMCP tools of brewless/laravel (`@webmcp`). Only tools that read.
  const context = document.modelContext ?? navigator.modelContext;
  const text = value => ({ content: [{ type: 'text', text: typeof value === 'string' ? value : JSON.stringify(value) }] });
  const api = {
    supported: Boolean(context && context.registerTool),
    text,
    // For a tool of the page's own; never throws, says whether the browser took it.
    register: async tool => {
      if (!api.supported) return false;
      try { await context.registerTool(tool); return true; } catch (error) { return false; }
    },
  };
  window.BrewlessWebMcp = api;

  const block = document.getElementById('brewless-webmcp');
  if (!block || !api.supported) return;

  for (const tool of JSON.parse(block.textContent)) {
    const annotations = { readOnlyHint: true };
    if (tool.untrusted) annotations.untrustedContentHint = true;

    const execute = tool.kind === 'data'
      ? async () => text(tool.data)
      : async input => {
          const url = new URL(tool.url, location.origin);
          if (url.origin !== location.origin) return text({ error: 'Only this site can be asked.' });
          for (const [key, value] of Object.entries(input || {})) {
            if (value !== undefined && value !== null) url.searchParams.set(key, typeof value === 'object' ? JSON.stringify(value) : String(value));
          }
          const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
          const json = (response.headers.get('content-type') || '').includes('json');
          const body = json ? await response.json() : await response.text();
          return text(response.ok ? body : { error: response.status, body });
        };

    api.register({ name: tool.name, title: tool.title || undefined, description: tool.description, inputSchema: tool.inputSchema, annotations, execute });
  }
})();
