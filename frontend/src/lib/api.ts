import axios from 'axios'

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
