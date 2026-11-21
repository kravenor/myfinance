import { AxiosError, type AxiosAdapter, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }))
const router = vi.hoisted(() => ({
  currentRoute: { value: { meta: { requiresAuth: true }, fullPath: '/transactions?page=2' } },
  push: vi.fn(),
}))
const auth = vi.hoisted(() => ({ isAuthenticated: true, user: { id: 1 } as { id: number } | null }))

vi.mock('@/stores/toast', () => ({ useToastStore: () => toast }))
vi.mock('@/router', () => ({ router }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))

type ApiModule = typeof import('./api')

let api: ApiModule['api']
let csrfGet: ReturnType<typeof vi.fn>
let adapter: ReturnType<typeof vi.fn<AxiosAdapter>>

function ok(config: InternalAxiosRequestConfig, data: unknown = {}) {
  return Promise.resolve({ data, status: 200, statusText: 'OK', headers: {}, config })
}

function fail(config: InternalAxiosRequestConfig, status?: number) {
  const response = status === undefined ? undefined : { data: {}, status, statusText: '', headers: {}, config }
  return Promise.reject(new AxiosError('errore', status ? String(status) : 'ERR_NETWORK', config, null, response))
}

beforeEach(async () => {
  vi.clearAllMocks()
  vi.resetModules()
  auth.isAuthenticated = true
  auth.user = { id: 1 }
  router.currentRoute.value = { meta: { requiresAuth: true }, fullPath: '/transactions?page=2' }

  // Modulo nuovo a ogni test: il flag csrfReady riparte da false.
  const axios = (await import('axios')).default
  csrfGet = vi.fn().mockResolvedValue({})
  vi.spyOn(axios, 'get').mockImplementation(csrfGet)
  ;({ api } = await import('./api'))
  adapter = vi.fn<AxiosAdapter>((config) => ok(config))
  api.defaults.adapter = adapter
})

describe('api: cookie CSRF', () => {
  it('non lo chiede per le GET', async () => {
    await api.get('/accounts')
    expect(csrfGet).not.toHaveBeenCalled()
  })

  it('lo chiede una sola volta prima delle richieste che scrivono', async () => {
    await api.post('/accounts', {})
    await api.delete('/accounts/1')
    expect(csrfGet).toHaveBeenCalledTimes(1)
    expect(csrfGet).toHaveBeenCalledWith('/sanctum/csrf-cookie', { withCredentials: true })
  })
})

describe('api: risposta 419', () => {
  it('rinnova il cookie e ripete la richiesta una volta', async () => {
    adapter.mockImplementationOnce((config) => fail(config, 419))
    const res = await api.post('/accounts', { name: 'Conto' })
    expect(res.status).toBe(200)
    expect(adapter).toHaveBeenCalledTimes(2)
    expect(csrfGet).toHaveBeenCalledTimes(2)
  })

  it('al secondo 419 rinuncia e rigetta', async () => {
    adapter.mockImplementation((config) => fail(config, 419))
    await expect(api.post('/accounts', {})).rejects.toMatchObject({ response: { status: 419 } })
    expect(adapter).toHaveBeenCalledTimes(2)
  })
})

describe('api: risposta 401', () => {
  it('su rotta protetta fa logout lato client e manda al login con redirect', async () => {
    adapter.mockImplementation((config) => fail(config, 401))
    await expect(api.get('/accounts')).rejects.toBeInstanceOf(AxiosError)
    expect(auth.user).toBeNull()
    expect(toast.info).toHaveBeenCalledWith('Sessione scaduta: accedi di nuovo.')
    expect(router.push).toHaveBeenCalledWith({ name: 'login', query: { redirect: '/transactions?page=2' } })
  })

  it('su rotta pubblica non fa nulla', async () => {
    router.currentRoute.value = { meta: { requiresAuth: false }, fullPath: '/login' }
    adapter.mockImplementation((config) => fail(config, 401))
    await expect(api.get('/accounts')).rejects.toBeInstanceOf(AxiosError)
    expect(auth.user).not.toBeNull()
    expect(router.push).not.toHaveBeenCalled()
  })

  it('le chiamate /auth/* gestiscono il 401 da sole', async () => {
    adapter.mockImplementation((config) => fail(config, 401))
    await expect(api.get('/auth/me')).rejects.toBeInstanceOf(AxiosError)
    expect(router.push).not.toHaveBeenCalled()
    expect(toast.info).not.toHaveBeenCalled()
  })
})

describe('api: toast sugli errori', () => {
  it('429 avvisa di attendere, tranne su /auth/*', async () => {
    adapter.mockImplementation((config) => fail(config, 429))
    await expect(api.get('/accounts')).rejects.toBeInstanceOf(AxiosError)
    expect(toast.info).toHaveBeenCalledWith('Troppe richieste in poco tempo: attendi un minuto e riprova.')

    toast.info.mockClear()
    await expect(api.post('/auth/login', {})).rejects.toBeInstanceOf(AxiosError)
    expect(toast.info).not.toHaveBeenCalled()
  })

  it('errore di rete mostra il toast di connessione', async () => {
    adapter.mockImplementation((config) => fail(config))
    await expect(api.get('/accounts')).rejects.toBeInstanceOf(AxiosError)
    expect(toast.error).toHaveBeenCalledWith('Connessione assente o server non raggiungibile. Riprova tra poco.')
  })

  it('5xx mostra un errore generico', async () => {
    adapter.mockImplementation((config) => fail(config, 503))
    await expect(api.get('/accounts')).rejects.toBeInstanceOf(AxiosError)
    expect(toast.error).toHaveBeenCalledWith('Errore del server: non dipende da te. Riprova tra poco.')
  })

  it('422 e 404 restano alla view, senza toast', async () => {
    for (const status of [422, 404]) {
      adapter.mockImplementationOnce((config) => fail(config, status))
      await expect(api.post('/accounts', {})).rejects.toMatchObject({ response: { status } })
    }
    expect(toast.error).not.toHaveBeenCalled()
    expect(toast.info).not.toHaveBeenCalled()
  })

  it('una richiesta annullata non mostra toast', async () => {
    const controller = new AbortController()
    controller.abort()
    await expect(api.get('/accounts', { signal: controller.signal })).rejects.toThrow()
    expect(toast.error).not.toHaveBeenCalled()
  })
})
