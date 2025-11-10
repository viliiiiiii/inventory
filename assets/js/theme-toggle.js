// Theme toggle for Punchlist
// - Saves selection to localStorage('pl-theme')
// - Respects OS preference on first visit
// - Adds data-theme="light|dark" on <html>

(function(){
  const KEY = 'pl-theme';
  const root = document.documentElement;

  function applyTheme(theme){
    if (theme !== 'light' && theme !== 'dark') return;
    root.setAttribute('data-theme', theme);
    try{ localStorage.setItem(KEY, theme); }catch(e){}
    // Accessible state on any toggle button
    document.querySelectorAll('[data-theme-toggle]').forEach(btn => {
      btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
      const label = theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
      btn.setAttribute('aria-label', label);
      const textEl = btn.querySelector('[data-theme-toggle-text]');
      if (textEl) textEl.textContent = theme === 'dark' ? 'Dark' : 'Light';
    });
  }

  function initialTheme(){
    const stored = (()=>{ try{ return localStorage.getItem(KEY); }catch(e){ return null; } })();
    if (stored === 'light' || stored === 'dark') return stored;
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    return prefersDark ? 'dark' : 'light';
  }

  // Initialize on load
  applyTheme(initialTheme());

  // Listen for toggle clicks
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-theme-toggle]');
    if (!btn) return;
    const current = root.getAttribute('data-theme') || initialTheme();
    applyTheme(current === 'dark' ? 'light' : 'dark');
  });

  // Optional: react to OS theme changes if user hasn't chosen explicitly
  try{
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    media.addEventListener('change', ev => {
      const stored = localStorage.getItem(KEY);
      if (stored !== 'light' && stored !== 'dark'){
        applyTheme(ev.matches ? 'dark' : 'light');
      }
    });
  }catch(e){}
})();
