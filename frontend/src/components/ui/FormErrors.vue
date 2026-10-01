<script setup lang="ts">
import { computed } from 'vue'
import type { FieldErrors } from '@/composables/useCrud'

const props = defineProps<{ errors: FieldErrors }>()
const messages = computed(() => Object.values(props.errors).flat())
</script>

<template>
  <div
    v-if="messages.length"
    role="alert"
    class="rounded-md border border-danger-200 bg-danger-50 px-3 py-2 text-sm text-danger-700"
  >
    <p class="font-medium">Correggi questi campi:</p>
    <ul class="mt-1 list-disc pl-5">
      <li v-for="m in messages" :key="m">{{ m }}</li>
    </ul>
  </div>
</template>
