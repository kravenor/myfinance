import { describe, expect, it } from 'vitest'
import { formatCurrency } from './money'

describe('formatCurrency', () => {
  it('formatta in it-IT', () => {
    expect(formatCurrency('12345.5')).toBe('12.345,50 €')
    expect(formatCurrency(-3, 'USD')).toBe('-3,00 USD')
  })

  it('ripiega su "valore VALUTA" se Intl non conosce la valuta', () => {
    expect(formatCurrency(12, 'XX')).toBe('12.00 XX')
  })

  it('mostra un trattino per importi non numerici', () => {
    expect(formatCurrency('abc')).toBe('—')
  })
})
