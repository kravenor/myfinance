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
  net_worth: string
  accounts: AccountBalance[]
}

export interface CategoryTotal {
  category_id: number | null
  category_name: string
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
