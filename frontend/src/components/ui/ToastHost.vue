<script setup lang="ts">
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()

const toneClass = {
  success: 'border-income-500',
  error: 'border-danger-500',
  info: 'border-primary-500',
}
const toneIcon = { success: '✓', error: '!', info: 'i' }
const toneIconClass = {
  success: 'bg-income-100 text-income-700',
  error: 'bg-danger-100 text-danger-700',
  info: 'bg-primary-100 text-primary-700',
}
</script>

<template>
  <div
    class="fixed z-50 inset-x-4 bottom-24 sm:inset-x-auto sm:right-6 sm:bottom-6 sm:w-96 flex flex-col gap-2 pointer-events-none"
    aria-live="polite"
    role="status"
  >
    <TransitionGroup
      enter-from-class="opacity-0 translate-y-2"
      leave-to-class="opacity-0"
      enter-active-class="transition duration-200"
      leave-active-class="transition duration-150"
    >
      <div
        v-for="t in toast.items"
        :key="t.id"
        class="pointer-events-auto flex items-start gap-3 rounded-lg border-l-4 bg-surface px-4 py-3 shadow-lg ring-1 ring-slate-200"
        :class="toneClass[t.tone]"
      >
        <span
          class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-bold"
          :class="toneIconClass[t.tone]"
          aria-hidden="true"
          >{{ toneIcon[t.tone] }}</span
        >
        <p class="flex-1 text-sm text-slate-800">{{ t.message }}</p>
        <button
          type="button"
          class="-mr-1 inline-flex h-6 w-6 items-center justify-center rounded text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
          aria-label="Chiudi"
          @click="toast.dismiss(t.id)"
        >
          ×
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>
