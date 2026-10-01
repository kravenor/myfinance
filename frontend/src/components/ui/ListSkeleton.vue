<script setup lang="ts">
withDefaults(defineProps<{ rows?: number }>(), { rows: 5 })
</script>

<template>
  <ul class="divide-y divide-slate-100" aria-busy="true" aria-label="Caricamento">
    <li v-for="i in rows" :key="i" class="flex animate-pulse items-center gap-4 px-4 py-3">
      <span class="h-4 w-24 rounded bg-slate-200" />
      <span class="h-4 flex-1 rounded bg-slate-100" />
      <span class="h-4 w-20 rounded bg-slate-200" />
    </li>
  </ul>
</template>
