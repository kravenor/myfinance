<script setup lang="ts">
import { computed } from 'vue'
import type { FieldErrors } from '@/composables/useCrud'

// Riepilogo degli errori 422 che non hanno un campo nel form (`shown` = campi con <FieldError>).
const props = defineProps<{ errors: FieldErrors; shown?: string[] }>()
const messages = computed(() =>
  Object.entries(props.errors)
    .filter(([key]) => !props.shown?.includes(key.split('.')[0]))
    .flatMap(([, msgs]) => msgs),
)
</script>

<template>
  <div
    v-if="messages.length"
    role="alert"
    class="rounded-md border border-danger-200 bg-danger-50 px-3 py-2 text-sm text-danger-700"
  >
    <ul class="list-disc pl-5">
      <li v-for="m in messages" :key="m">{{ m }}</li>
    </ul>
  </div>
</template>
