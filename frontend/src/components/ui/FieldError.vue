<script setup lang="ts">
import type { FieldErrors } from '@/composables/useCrud'

// Messaggio 422 sotto il campo; l'input usa :class="{ 'input-invalid': fieldErrors.<name> }".
defineProps<{ errors: FieldErrors; name: string }>()
</script>

<template>
  <p v-if="errors[name]?.length" class="field-error">{{ errors[name][0] }}</p>
</template>
