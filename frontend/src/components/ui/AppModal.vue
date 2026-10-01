<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'

// <dialog> nativo: focus trap, Esc e backdrop senza librerie.
const open = defineModel<boolean>({ required: true })
defineProps<{ title: string; size?: 'sm' | 'md' | 'lg' }>()

const el = ref<HTMLDialogElement | null>(null)

function sync(v: boolean) {
  if (!el.value) return
  if (v && !el.value.open) el.value.showModal()
  if (!v && el.value.open) el.value.close()
}

watch(open, sync, { flush: 'post' })
onMounted(() => sync(open.value))

// Click sul backdrop: il target è il <dialog> stesso, non il pannello interno.
function onClick(e: MouseEvent) {
  if (e.target === el.value) open.value = false
}
</script>

<template>
  <dialog
    ref="el"
    class="modal"
    :class="size === 'sm' ? 'sm:max-w-md' : size === 'lg' ? 'sm:max-w-4xl' : 'sm:max-w-2xl'"
    :aria-label="title"
    @close="open = false"
    @click="onClick"
  >
    <div v-if="open" class="flex max-h-[inherit] flex-col">
      <header class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-6">
        <h2 class="text-base font-semibold text-slate-900">{{ title }}</h2>
        <button
          type="button"
          class="icon-btn text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:ring-primary-500"
          aria-label="Chiudi"
          @click="open = false"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 20 20"
            fill="currentColor"
            class="h-5 w-5"
            aria-hidden="true"
          >
            <path
              d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"
            />
          </svg>
        </button>
      </header>
      <div class="flex-1 overflow-y-auto">
        <slot />
      </div>
    </div>
  </dialog>
</template>
