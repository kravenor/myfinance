export interface NotificationPreferences {
  email: boolean
  email_address: string | null
  budget: boolean
  savings_goals: boolean
  budget_threshold: number
}

export interface User {
  id: number
  name: string
  email: string
  currency: string
  locale: string
  notification_preferences?: NotificationPreferences
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
  transfer_amount: string | null
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

export type RuleMatchType = 'contains' | 'starts_with' | 'equals' | 'regex'
export type RuleAppliesTo = 'any' | 'income' | 'expense'
export interface CategorizationRule {
  id: number
  category_id: number
  category?: { id: number; name: string; color: string | null; type: CategoryType }
  name: string
  match_type: RuleMatchType
  pattern: string
  applies_to_type: RuleAppliesTo
  priority: number
  is_active: boolean
  times_applied: number
  last_applied_at: string | null
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

export type SavingsGoalStatus = 'active' | 'completed' | 'archived'
export type SavingsGoalRecurrence = 'none' | 'weekly' | 'monthly' | 'yearly'
export type PaceStatus = 'on_track' | 'behind' | 'overdue' | 'completed'
export interface SavingsGoalPace {
  target_date: string
  days_left: number
  months_left: number
  required_per_month: string
  status: PaceStatus
}
export interface SavingsGoal {
  id: number
  name: string
  target_amount: string
  currency: string
  account_id: number | null
  target_date: string | null
  recurrence: SavingsGoalRecurrence
  start_date: string | null
  color: string | null
  icon: string | null
  status: SavingsGoalStatus
  notes: string | null
  saved?: string
  progress?: number
  remaining?: string
  period_start?: string | null
  period_end?: string | null
  pace?: SavingsGoalPace | null
  created_at: string
  updated_at: string
}

export type AssetType = 'stock' | 'etf' | 'fund' | 'bond' | 'crypto' | 'commodity' | 'cash' | 'other'
export interface InvestmentHolding {
  id: number
  account_id: number
  name: string
  symbol: string | null
  asset_type: AssetType
  currency: string
  quantity: string
  avg_cost: string
  last_price: string | null
  last_price_at: string | null
  notes: string | null
  effective_price: string
  price_source: 'auto' | 'manual' | 'cost'
  price_as_of: string | null
  cost_basis: string
  market_value: string
  unrealized_pl: string
  unrealized_pl_pct: string | null
  created_at: string
  updated_at: string
}

export interface InvestmentOverview {
  base_currency: string
  holdings_count: number
  total_market_value: string
  total_cost_basis: string
  total_unrealized_pl: string
  total_unrealized_pl_pct: string | null
  by_asset_type: { asset_type: AssetType; market_value: string; pct: string }[]
  accounts: {
    account_id: number
    name: string | null
    currency: string | null
    market_value: string
    cost_basis: string
    unrealized_pl: string
  }[]
}

export interface AppNotification {
  id: string
  type: string | null
  level: string | null
  title: string
  message: string
  url: string | null
  read_at: string | null
  created_at: string | null
}

export type ScenarioCadence = 'one_time' | 'monthly' | 'quarterly' | 'yearly'
export interface ScenarioItem {
  id: number
  scenario_id: number
  account_id: number | null
  category_id: number | null
  description: string | null
  amount: string
  currency: string
  cadence: ScenarioCadence
  interval: number
  starts_on: string
  ends_on: string | null
  created_at: string
  updated_at: string
}

export interface Scenario {
  id: number
  name: string
  description: string | null
  color: string | null
  is_active: boolean
  items_count?: number
  items?: ScenarioItem[]
  created_at: string
  updated_at: string
}

