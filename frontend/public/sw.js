// Service worker vuoto, di proposito: serve solo a soddisfare il criterio di
// installabilità di Chrome (manifest + SW con un handler `fetch`), che altrimenti
// non offre "Installa app" su Android. iOS installa già col solo manifest.
//
// ponytail: nessuna cache. Un'app di dati vive di richieste fresche e una cache
// offline qui darebbe solo saldi vecchi da debuggare. Se un giorno serve la
// lettura offline, il posto è questo.
self.addEventListener('fetch', () => {})
