import { api } from '@/lib/api'

// Web Push sul dispositivo corrente. Il service worker è registrato solo nella build di
// produzione (main.ts), quindi in sviluppo lo stato è sempre 'no-worker'.
export type PushState = 'unsupported' | 'ios-not-installed' | 'no-worker' | 'no-server-key' | 'denied' | 'off' | 'on'

const isIos = () => /iphone|ipad|ipod/i.test(navigator.userAgent)
const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches

async function registration(): Promise<ServiceWorkerRegistration | undefined> {
  return 'serviceWorker' in navigator ? navigator.serviceWorker.getRegistration() : undefined
}

async function serverKey(): Promise<string | null> {
  const { data } = await api.get<{ data: { public_key: string | null } }>('/push-subscriptions/key')
  return data.data.public_key
}

// La chiave VAPID arriva in base64url; PushManager vuole i byte.
function keyBytes(base64url: string): Uint8Array<ArrayBuffer> {
  const base64 = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(base64)
  const bytes = new Uint8Array(new ArrayBuffer(raw.length))
  for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i)
  return bytes
}

export async function pushState(): Promise<PushState> {
  if (isIos() && !isStandalone()) return 'ios-not-installed'
  if (!('PushManager' in window) || !('Notification' in window)) return 'unsupported'
  const reg = await registration()
  if (!reg) return 'no-worker'
  if (!(await serverKey())) return 'no-server-key'
  if (Notification.permission === 'denied') return 'denied'
  return (await reg.pushManager.getSubscription()) ? 'on' : 'off'
}

async function saveSubscription(sub: PushSubscription): Promise<void> {
  const json = sub.toJSON()
  await api.post('/push-subscriptions', {
    endpoint: json.endpoint,
    keys: json.keys,
    content_encoding: (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings?.[0] ?? 'aes128gcm',
  })
}

// All'avvio: il browser può rinnovare la sottoscrizione per conto suo e il server terrebbe
// quella vecchia (poi scaduta). Reinviarla è idempotente; se l'utente le ha disattivate non c'è nulla da inviare.
export async function syncPush(): Promise<void> {
  if (!('Notification' in window) || Notification.permission !== 'granted') return
  const sub = await (await registration())?.pushManager.getSubscription()
  if (sub) await saveSubscription(sub)
}

// Va chiamata da un gesto dell'utente: il browser mostra la richiesta di permesso.
export async function enablePush(): Promise<PushState> {
  const reg = await registration()
  const key = await serverKey()
  if (!reg || !key) return pushState()
  if ((await Notification.requestPermission()) !== 'granted') return pushState()

  await saveSubscription(await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key) }))
  return 'on'
}

export async function disablePush(): Promise<PushState> {
  const sub = await (await registration())?.pushManager.getSubscription()
  if (sub) {
    await api.delete('/push-subscriptions', { data: { endpoint: sub.endpoint } })
    await sub.unsubscribe()
  }
  return pushState()
}

export async function sendTestPush(): Promise<void> {
  await api.post('/push-subscriptions/test')
}
