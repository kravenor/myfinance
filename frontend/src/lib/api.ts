import axios, { type AxiosError, type InternalAxiosRequestConfig } from 'axios'
import { useToastStore } from '@/stores/toast'

const baseURL = import.meta.env.VITE_API_URL ?? '/api'

export const api = axios.create({
  baseURL,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

let csrfReady = false

export async function ensureCsrf(): Promise<void> {
  if (csrfReady) return
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
  csrfReady = true
}

api.interceptors.request.use(async (config) => {
  const method = (config.method ?? 'get').toLowerCase()
  if (['post', 'put', 'patch', 'delete'].includes(method)) {
    await ensureCsrf()
  }
  return config
})

type RetriableConfig = InternalAxiosRequestConfig & { _csrfRetried?: boolean }

api.interceptors.response.use(undefined, async (error: AxiosError) => {
  const status = error.response?.status
  const config = error.config as RetriableConfig | undefined

  // Token CSRF scaduto: nuovo cookie e un solo nuovo tentativo.
  if (status === 419 && config && !config._csrfRetried) {
    csrfReady = false
    config._csrfRetried = true
    await ensureCsrf()
    return api.request(config)
  }

  // Sessione scaduta su una rotta protetta (le /auth/* gestiscono il 401 da sole).
  if (status === 401 && !config?.url?.startsWith('/auth/')) {
    const [{ router }, { useAuthStore }] = await Promise.all([import('@/router'), import('@/stores/auth')])
    const auth = useAuthStore()
    const current = router.currentRoute.value
    if (auth.isAuthenticated && current.meta.requiresAuth) {
      auth.user = null
      useToastStore().info('Sessione scaduta: accedi di nuovo.')
      router.push({ name: 'login', query: { redirect: current.fullPath } })
    }
  } else if (status === 429 && !config?.url?.startsWith('/auth/')) {
    useToastStore().info('Troppe richieste in poco tempo: attendi un minuto e riprova.')
  } else if (!error.response && !axios.isCancel(error)) {
    useToastStore().error('Connessione assente o server non raggiungibile. Riprova tra poco.')
  } else if (status && status >= 500) {
    useToastStore().error('Errore del server: non dipende da te. Riprova tra poco.')
  }

  return Promise.reject(error)
})
