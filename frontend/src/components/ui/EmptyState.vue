<script setup lang="ts">
// filtered: la lista è vuota per i filtri attivi, non perché non ci sono dati.
defineProps<{ title: string; filtered?: boolean }>()
defineEmits<{ (e: 'reset'): void }>()
</script>

<template>
  <div class="px-4 py-10 text-center">
    <p class="text-sm font-medium text-slate-700">
      {{ filtered ? 'Nessun risultato con questi filtri.' : title }}
    </p>
    <div class="mt-3 flex justify-center">
      <button v-if="filtered" type="button" class="btn-secondary" @click="$emit('reset')">Azzera filtri</button>
      <slot v-else />
    </div>
  </div>
</template>
