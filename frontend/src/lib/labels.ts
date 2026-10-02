import type {
  AccountType,
  AssetType,
  Cadence,
  CategoryType,
  InvestmentSide,
  RuleAppliesTo,
  RuleMatchType,
  SavingsGoalRecurrence,
  ScenarioCadence,
  TransactionType,
} from '@/types/api'

// Etichette di display: i valori inviati all'API restano quelli inglesi.
export const TX_TYPE_LABEL: Record<TransactionType, string> = {
  income: 'Entrata',
  expense: 'Uscita',
  transfer: 'Giroconto',
}
export const TX_TYPES = Object.keys(TX_TYPE_LABEL) as TransactionType[]

export const CATEGORY_TYPE_LABEL: Record<CategoryType, string> = { income: 'Entrata', expense: 'Uscita' }

export const ACCOUNT_TYPE_LABEL: Record<AccountType, string> = {
  cash: 'Contanti',
  bank: 'Conto bancario',
  card: 'Carta',
  investment: 'Investimenti',
  other: 'Altro',
}

export const ASSET_TYPE_LABEL: Record<AssetType, string> = {
  stock: 'Azione',
  etf: 'ETF',
  fund: 'Fondo',
  bond: 'Obbligazione',
  crypto: 'Cripto',
  commodity: 'Materia prima',
  certificate: 'Certificato',
  cash: 'Liquidità',
  other: 'Altro',
}

export const INVESTMENT_SIDE_LABEL: Record<InvestmentSide, string> = { buy: 'Acquisto', sell: 'Vendita', fee: 'Costo' }

export const RULE_MATCH_LABEL: Record<RuleMatchType, string> = {
  contains: 'contiene',
  starts_with: 'inizia con',
  equals: 'uguale a',
  regex: 'espressione regolare',
}
export const RULE_APPLIES_LABEL: Record<RuleAppliesTo, string> = { any: 'Tutte', income: 'Entrate', expense: 'Uscite' }

export const RECURRENCE_LABEL: Record<SavingsGoalRecurrence, string> = {
  none: 'Una tantum',
  weekly: 'Settimanale',
  monthly: 'Mensile',
  yearly: 'Annuale',
}

export const CADENCE_LABEL: Record<Cadence, string> = {
  daily: 'Giornaliera',
  weekly: 'Settimanale',
  biweekly: 'Quindicinale',
  monthly: 'Mensile',
  quarterly: 'Trimestrale',
  yearly: 'Annuale',
}

export const SCENARIO_CADENCE_LABEL: Record<ScenarioCadence, string> = {
  one_time: 'Una tantum',
  monthly: 'Mensile',
  quarterly: 'Trimestrale',
  yearly: 'Annuale',
}

// "ogni 2 settimane", "ogni mese": testo di una cadenza con intervallo.
const CADENCE_UNIT: Record<Cadence, [string, string]> = {
  daily: ['giorno', 'giorni'],
  weekly: ['settimana', 'settimane'],
  biweekly: ['quindicina', 'quindicine'],
  monthly: ['mese', 'mesi'],
  quarterly: ['trimestre', 'trimestri'],
  yearly: ['anno', 'anni'],
}
export function cadenceText(interval: number, cadence: Cadence): string {
  const [one, many] = CADENCE_UNIT[cadence]
  return interval > 1 ? `ogni ${interval} ${many}` : `ogni ${one}`
}

export const NOTIFICATION_LEVEL_LABEL: Record<string, string> = {
  exceeded: 'Sforato',
  warning: 'In allerta',
  overdue: 'Scaduto',
  behind: 'In ritardo',
  info: 'Info',
}
