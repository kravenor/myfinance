import { AxiosError } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useCrud } from './useCrud'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() }))

vi.mock('@/lib/api', () => ({ api }))

type Item = { id: number; name: string }

const meta = { current_page: 1, last_page: 1, per_page: 25, total: 2, from: 1, to: 2 }

function httpError(status: number, data: unknown = {}) {
  return new AxiosError('errore', String(status), undefined, null, {
    status,
    data,
    statusText: '',
    headers: {},
    config: {} as never,
  })
}

async function loaded() {
  api.get.mockResolvedValueOnce({
    data: {
      data: [
        { id: 1, name: 'Uno' },
        { id: 2, name: 'Due' },
      ],
      meta,
    },
  })
  const crud = useCrud<Item>('accounts')
  await crud.list()
  return crud
}

beforeEach(() => {
  vi.resetAllMocks()
})

describe('useCrud.list', () => {
  it('carica elementi e meta passando i parametri', async () => {
    const crud = await loaded()
    expect(api.get).toHaveBeenCalledWith('/accounts', { params: {} })
    expect(crud.items.value.map((i) => i.id)).toEqual([1, 2])
    expect(crud.meta.value).toEqual(meta)
    expect(crud.loading.value).toBe(false)
    expect(crud.error.value).toBeNull()
  })

  it('segna loading durante la richiesta', async () => {
    let resolve!: (v: unknown) => void
    api.get.mockReturnValueOnce(new Promise((r) => (resolve = r)))
    const crud = useCrud<Item>('accounts')
    const pending = crud.list({ page: 2 })
    expect(crud.loading.value).toBe(true)
    expect(api.get).toHaveBeenCalledWith('/accounts', { params: { page: 2 } })
    resolve({ data: { data: [], meta } })
    await pending
    expect(crud.loading.value).toBe(false)
  })

  it('in errore espone il messaggio del server o uno generico, senza rilanciare', async () => {
    const crud = useCrud<Item>('accounts')
    api.get.mockRejectedValueOnce(httpError(403, { message: 'Non autorizzato.' }))
    await crud.list()
    expect(crud.error.value).toBe('Non autorizzato.')

    api.get.mockRejectedValueOnce(new Error('rete'))
    await crud.list()
    expect(crud.error.value).toBe('Errore caricamento.')
    expect(crud.loading.value).toBe(false)
  })
})

describe('useCrud.create / update', () => {
  it('create aggiunge in testa e restituisce l\'elemento', async () => {
    const crud = await loaded()
    api.post.mockResolvedValueOnce({ data: { data: { id: 3, name: 'Tre' } } })
    const created = await crud.create({ name: 'Tre' })
    expect(api.post).toHaveBeenCalledWith('/accounts', { name: 'Tre' })
    expect(created).toEqual({ id: 3, name: 'Tre' })
    expect(crud.items.value.map((i) => i.id)).toEqual([3, 1, 2])
  })

  it('update sostituisce l\'elemento nella lista', async () => {
    const crud = await loaded()
    api.patch.mockResolvedValueOnce({ data: { data: { id: 2, name: 'Due bis' } } })
    await crud.update(2, { name: 'Due bis' })
    expect(api.patch).toHaveBeenCalledWith('/accounts/2', { name: 'Due bis' })
    expect(crud.items.value[1]).toEqual({ id: 2, name: 'Due bis' })
  })

  it('update di un elemento non in lista non tocca la lista', async () => {
    const crud = await loaded()
    api.patch.mockResolvedValueOnce({ data: { data: { id: 9, name: 'Altro' } } })
    await crud.update(9, { name: 'Altro' })
    expect(crud.items.value.map((i) => i.id)).toEqual([1, 2])
  })

  it('submitting resta true solo durante l\'invio', async () => {
    const crud = useCrud<Item>('accounts')
    let resolve!: (v: unknown) => void
    api.post.mockReturnValueOnce(new Promise((r) => (resolve = r)))
    const pending = crud.create({ name: 'X' })
    expect(crud.submitting.value).toBe(true)
    resolve({ data: { data: { id: 1, name: 'X' } } })
    await pending
    expect(crud.submitting.value).toBe(false)
  })

  it('un 422 riempie fieldErrors e rilancia l\'errore', async () => {
    const crud = useCrud<Item>('accounts')
    const errors = { name: ['Il campo nome è obbligatorio.'] }
    api.post.mockRejectedValueOnce(httpError(422, { errors }))
    await expect(crud.create({})).rejects.toBeInstanceOf(AxiosError)
    expect(crud.fieldErrors.value).toEqual(errors)
    expect(crud.submitting.value).toBe(false)
    expect(crud.items.value).toEqual([])
  })

  it('un nuovo invio azzera gli errori precedenti; gli altri errori non li valorizzano', async () => {
    const crud = useCrud<Item>('accounts')
    api.post.mockRejectedValueOnce(httpError(422, { errors: { name: ['x'] } }))
    await expect(crud.create({})).rejects.toThrow()

    api.patch.mockRejectedValueOnce(httpError(500))
    await expect(crud.update(1, {})).rejects.toThrow()
    expect(crud.fieldErrors.value).toEqual({})
  })
})

describe('useCrud.destroy', () => {
  it('rimuove l\'elemento dopo la DELETE', async () => {
    const crud = await loaded()
    api.delete.mockResolvedValueOnce({})
    await crud.destroy(1)
    expect(api.delete).toHaveBeenCalledWith('/accounts/1')
    expect(crud.items.value.map((i) => i.id)).toEqual([2])
  })

  it('se la DELETE fallisce lascia la lista intatta e rilancia', async () => {
    const crud = await loaded()
    api.delete.mockRejectedValueOnce(httpError(404))
    await expect(crud.destroy(1)).rejects.toBeInstanceOf(AxiosError)
    expect(crud.items.value.map((i) => i.id)).toEqual([1, 2])
  })
})
