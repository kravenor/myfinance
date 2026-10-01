<script setup lang="ts">
import { computed } from 'vue'
import { formatCurrency } from '@/lib/money'

// Classifica di totali con quota sul totale: fa anche da legenda della ciambella (stessi colori).
const props = defineProps<{ items: { key: string | number; label: string; value: number; color: string }[]; currency: string }>()
const total = computed(() => props.items.reduce((s, i) => s + i.value, 0))
const pct = (v: number) => (total.value > 0 ? (v / total.value) * 100 : 0)
</script>

<template>
  <ul class="divide-y divide-slate-100">
    <li v-for="item in items" :key="item.key" class="py-2.5">
      <div class="flex items-center gap-3 text-sm">
        <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: item.color }" aria-hidden="true" />
        <span class="min-w-0 flex-1 truncate text-slate-800">{{ item.label }}</span>
        <span class="num font-medium text-slate-900">{{ formatCurrency(item.value, currency) }}</span>
        <span class="num w-12 text-right text-xs text-slate-500">{{ pct(item.value).toFixed(1) }}%</span>
      </div>
      <div class="ml-5 mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
        <div class="h-full rounded-full" :style="{ width: pct(item.value) + '%', background: item.color }" />
      </div>
    </li>
  </ul>
</template>
