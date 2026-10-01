<script setup lang="ts">
import { onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import ToastHost from '@/components/ui/ToastHost.vue'

const auth = useAuthStore()

onMounted(async () => {
  await auth.fetchMe()
})
</script>

<template>
  <RouterView />
  <ToastHost />
</template>
