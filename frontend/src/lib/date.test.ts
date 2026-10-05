import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types/api'
import { financialMonthRange, formatDateWith, formatMonth } from './date'

function loginWith(prefs: Partial<User>) {
  useAuthStore().user = { date_format: 'd/m/Y', month_start_day: 1, ...prefs } as User
}

beforeEach(() => setActivePinia(createPinia()))

describe('formatDateWith', () => {
  it('applica il formato richiesto', () => {
    expect(formatDateWith('2026-03-05', 'd/m/Y')).toBe('05/03/2026')
    expect(formatDateWith('2026-03-05', 'Y-m-d')).toBe('2026-03-05')
    expect(formatDateWith('2026-03-05', 'd.m.Y')).toBe('05.03.2026')
  })

  // `npm test` gira con TZ=America/New_York: in UTC questo test passerebbe anche col bug.
  it('legge le date solo-giorno come locali, senza slittare di un giorno', () => {
    expect(formatDateWith('2026-01-01', 'd/m/Y')).toBe('01/01/2026')
  })

  it('mostra un trattino per valori vuoti o non validi', () => {
    expect(formatDateWith(null)).toBe('—')
    expect(formatDateWith('non-una-data')).toBe('—')
  })
})

describe('formatMonth', () => {
  it('toglie il giorno dal formato preferito', () => {
    loginWith({ date_format: 'd/m/Y' })
    expect(formatMonth('2026-09')).toBe('09/2026')

    loginWith({ date_format: 'Y-m-d' })
    expect(formatMonth('2026-09')).toBe('2026-09')
  })

  it('lascia invariati i periodi non mensili', () => {
    expect(formatMonth('2026')).toBe('2026')
  })
})

describe('financialMonthRange', () => {
  it('con inizio al 1° coincide con il mese di calendario', () => {
    loginWith({ month_start_day: 1 })
    expect(financialMonthRange(new Date(2026, 2, 10))).toEqual({ from: '2026-03-01', to: '2026-03-31' })
  })

  it('prima del giorno di inizio appartiene al mese finanziario precedente', () => {
    loginWith({ month_start_day: 25 })
    expect(financialMonthRange(new Date(2026, 2, 10))).toEqual({ from: '2026-02-25', to: '2026-03-24' })
    expect(financialMonthRange(new Date(2026, 2, 25))).toEqual({ from: '2026-03-25', to: '2026-04-24' })
  })

  it('limita il giorno di inizio a 28', () => {
    loginWith({ month_start_day: 31 })
    expect(financialMonthRange(new Date(2026, 1, 28)).from).toBe('2026-02-28')
  })
})
