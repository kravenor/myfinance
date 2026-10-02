<script setup lang="ts">
import { MUTED_COLOR } from '@/lib/chartTheme'

// Colore della categoria, grigio se non ne ha uno. Decorativo: il nome è sempre accanto.
defineProps<{ color?: string | null }>()
</script>

<template>
  <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: color || MUTED_COLOR }" aria-hidden="true" />
</template>
