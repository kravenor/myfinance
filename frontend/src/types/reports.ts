export interface AccountBalance {
  id: number
  name: string
  currency: string
  balance: string
}

export interface ReportSummary {
  from: string
  to: string
  income: string
  expense: string
  net: string
  saving_rate: string
  net_worth: string
  accounts: AccountBalance[]
}

export interface PeriodTotals {
  label: string
  from: string
  to: string
  income: string
  expense: string
  net: string
}

export interface PeriodComparison {
  unit: 'month' | 'year'
  current: PeriodTotals
  previous: PeriodTotals
  delta: {
    income: string
    income_pct: string | null
    expense: string
    expense_pct: string | null
    net: string
  }
}

export interface CategoryTrendSeries {
  category_id: number
  category_name: string
  values: string[]
}

export interface CategoryTrend {
  periods: string[]
  categories: CategoryTrendSeries[]
}

export interface TopTransaction {
  id: number
  occurred_at: string
  type: 'income' | 'expense' | 'transfer'
  amount: string
  currency: string
  account_name: string | null
  category_name: string | null
  description: string | null
}

export interface CashFlowPoint {
  period: string
  income: string
  expense: string
  net: string
  projected_net_worth: string
}

export interface CategoryTotal {
  category_id: number | null
  category_name: string
  total: string
}

export interface TagTotal {
  tag_id: number
  tag_name: string
  tag_color: string | null
  total: string
}

export interface TimelinePoint {
  period: string
  income: string
  expense: string
  net: string
}

export interface NetWorthPoint {
  period: string
  net_worth: string
}

export interface BudgetAlert {
  budget_id: number
  category_id: number
  category_name: string | null
  category_color: string | null
  year: number
  month: number
  amount: string
  spent: string
  percent: number
  status: 'warning' | 'exceeded'
}
