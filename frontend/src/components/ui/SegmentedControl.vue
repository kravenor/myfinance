<script setup lang="ts" generic="T extends string">
// Scelta tra poche opzioni sempre visibili (schede, tipo, periodo): un tap invece di una select.
const model = defineModel<T>({ required: true })
defineProps<{ options: { value: T; label: string; count?: number }[]; label: string }>()
</script>

<template>
  <div role="group" :aria-label="label" class="inline-flex max-w-full overflow-x-auto rounded-lg bg-slate-200/70 p-1">
    <button
      v-for="opt in options"
      :key="opt.value"
      type="button"
      class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1.5 sm:px-3 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
      :class="model === opt.value ? 'bg-surface text-slate-900 shadow-sm ring-1 ring-slate-300' : 'text-slate-600 hover:text-slate-900'"
      :aria-pressed="model === opt.value"
      @click="model = opt.value"
    >
      {{ opt.label }}
      <span v-if="opt.count !== undefined" class="rounded-full bg-slate-300/60 px-1.5 text-xs text-slate-700">{{ opt.count }}</span>
    </button>
  </div>
</template>
