<script setup lang="ts">
import { ICONS, type IconName } from '@/components/ui/icons'

defineProps<{ name: IconName }>()
</script>

<template>
  <svg
    xmlns="http://www.w3.org/2000/svg"
    fill="none"
    viewBox="0 0 24 24"
    stroke-width="1.5"
    stroke="currentColor"
    aria-hidden="true"
  >
    <path v-for="d in ICONS[name]" :key="d" stroke-linecap="round" stroke-linejoin="round" :d="d" />
  </svg>
</template>
