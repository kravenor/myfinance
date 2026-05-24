export interface User {
  id: number
  name: string
  email: string
  currency: string
  locale: string
  created_at: string
}

export interface Paginated<T> {
  data: T[]
  links: { first: string; last: string; prev: string | null; next: string | null }
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}

export type AccountType = 'cash' | 'bank' | 'card' | 'investment' | 'other'
export interface Account {
  id: number
  name: string
  type: AccountType
  currency: string
  initial_balance: string
  color: string | null
  icon: string | null
  is_archived: boolean
  include_in_net_worth: boolean
  is_primary: boolean
  notes: string | null
  created_at: string
  updated_at: string
}

export type CategoryType = 'income' | 'expense'
export interface Category {
  id: number
  parent_id: number | null
  name: string
  type: CategoryType
  color: string | null
  icon: string | null
  is_archived: boolean
  sort_order: number
  created_at: string
  updated_at: string
}

export interface Tag {
  id: number
  name: string
  color: string | null
  created_at: string
  updated_at: string
}

export type TransactionType = 'income' | 'expense' | 'transfer'
export interface Transaction {
  id: number
  account_id: number
  category_id: number | null
  transfer_account_id: number | null
  recurring_transaction_id: number | null
  type: TransactionType
  amount: string
  currency: string
  occurred_at: string
  description: string | null
  notes: string | null
  external_id: string | null
  tags?: Tag[]
  created_at: string
  updated_at: string
}

export interface Budget {
  id: number
  category_id: number
  year: number
  month: number
  amount: string
  spent?: string
  created_at: string
  updated_at: string
}

export type Cadence = 'daily' | 'weekly' | 'biweekly' | 'monthly' | 'quarterly' | 'yearly'
export interface RecurringTransaction {
  id: number
  account_id: number
  category_id: number | null
  transfer_account_id: number | null
  type: TransactionType
  amount: string
  currency: string
  description: string | null
  cadence: Cadence
  interval: number
  starts_on: string
  ends_on: string | null
  next_run_at: string
  last_run_at: string | null
  is_active: boolean
  created_at: string
  updated_at: string
}
