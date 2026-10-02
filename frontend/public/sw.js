// Service worker: installabilità della PWA e notifiche push.
//
// ponytail: nessuna cache. Un'app di dati vive di richieste fresche e una cache
// offline qui darebbe solo saldi vecchi da debuggare. Se un giorno serve la
// lettura offline, il posto è questo.
self.addEventListener('fetch', () => {})

// Push dal server (laravel-notification-channels/webpush): { title, body, icon, badge, tag, data: { url } }.
self.addEventListener('push', (event) => {
  const payload = event.data ? event.data.json() : {}
  const { title = 'Finance', ...options } = payload

  event.waitUntil(
    Promise.all([
      self.registration.showNotification(title, options),
      // L'app aperta aggiorna lista e badge senza ricaricare.
      self.clients.matchAll({ type: 'window' }).then((clients) =>
        clients.forEach((client) => client.postMessage({ type: 'notifications:refresh' })),
      ),
    ]),
  )
})

// Click: porta in primo piano una finestra dell'app sull'URL della notifica, o ne apre una.
self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = new URL(event.notification.data?.url || '/', self.location.origin).href

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      const client = clients.find((c) => new URL(c.url).origin === self.location.origin)
      // navigate() funziona solo sulle finestre controllate dal SW: altrimenti se ne apre una nuova.
      if (client) return client.navigate(url).then((c) => (c || client).focus()).catch(() => self.clients.openWindow(url))
      return self.clients.openWindow(url)
    }),
  )
})
