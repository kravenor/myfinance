<script setup lang="ts">
import { computed } from 'vue'
import { formatCurrency } from '@/lib/money'
import type { TransactionType } from '@/types/api'

const props = defineProps<{
  value: string | number
  currency: string
  // Con type il colore/segno segue il tipo, senza segue il segno del valore (es. saldi, P/L).
  type?: TransactionType
  signed?: boolean
}>()

const tone = computed(() => {
  const n = Number(props.value)
  if (n === 0) return 'neutral'
  if (props.type) return props.type
  if (!props.signed) return 'neutral'
  return n > 0 ? 'income' : 'expense'
})

const text = computed(() => {
  const n = Math.abs(Number(props.value))
  const sign = tone.value === 'income' ? '+' : tone.value === 'expense' ? '−' : ''
  return sign + formatCurrency(props.type || props.signed ? n : props.value, props.currency)
})

const toneClass: Record<string, string> = {
  income: 'text-income-600',
  expense: 'text-expense-600',
  transfer: 'text-transfer-700',
  neutral: '',
}
</script>

<template>
  <span class="num" :class="toneClass[tone]">{{ text }}</span>
</template>
