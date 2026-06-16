export interface AccountBalance {
  id: number
  name: string
  currency: string
  balance: string
  balance_base: string
}

export interface ReportSummary {
  from: string
  to: string
  base_currency: string
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
  base_currency: string
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
  amount_base: string
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

export interface ExpenseForecastCell {
  period: string
  recurring: string
  budget: string | null
  scenario: string
  forecast_base: string
  total: string
  budget_breach: boolean
}

export interface ExpenseForecastCategory {
  category_id: number | null
  category_name: string
  color: string | null
  total: string
  monthly: ExpenseForecastCell[]
}

export interface ExpenseForecastMonthTotal {
  period: string
  income: string
  recurring: string
  budget: string
  scenario: string
  forecast_base: string
  expense_total: string
  net: string
}

export interface ExpenseForecastSummary {
  total_income: string
  total_expense: string
  total_net: string
  min_monthly_net: string
  min_monthly_net_period: string | null
  months_count: number
}

export interface ExpenseForecastScenarioMeta {
  id: number
  name: string
  color: string | null
  is_active: boolean
}

export interface ExpenseForecast {
  base_currency: string
  months: string[]
  categories: ExpenseForecastCategory[]
  totals_by_month: ExpenseForecastMonthTotal[]
  summary: ExpenseForecastSummary
  scenario: ExpenseForecastScenarioMeta | null
}

export interface ExpenseForecastCompare {
  base_currency: string
  months: string[]
  baseline: ExpenseForecast
  scenarios: ExpenseForecast[]
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
