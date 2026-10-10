// Tema prima del primo paint (stessa logica di stores/theme.ts). File esterno e non inline:
// la CSP di produzione ammette solo script da 'self'.
try {
  var t = localStorage.getItem('theme')
  if (t === 'dark' || (t !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.classList.add('dark')
    document.querySelector('meta[name="theme-color"]').setAttribute('content', '#0f172a')
  }
} catch {}
