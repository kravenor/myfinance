<script setup lang="ts">
import { formatDate } from '@/lib/date'
import { LEGAL } from '@/lib/legal'
import { useAuthStore } from '@/stores/auth'
import LegalLinks from '@/components/LegalLinks.vue'

// Pagina pubblica (leggibile anche senza account): ritorno all'app o al login.
defineProps<{ title: string }>()
const auth = useAuthStore()
</script>

<template>
  <div class="min-h-screen px-4 py-8 sm:py-12">
    <article class="legal card mx-auto max-w-3xl p-5 sm:p-8">
      <RouterLink
        :to="auth.isAuthenticated ? { name: 'dashboard' } : { name: 'login' }"
        class="text-sm font-medium text-primary-600 hover:text-primary-700"
      >
        ← {{ auth.isAuthenticated ? 'Torna all\'app' : 'Torna all\'accesso' }}
      </RouterLink>
      <h1 class="mt-4 text-2xl font-semibold text-slate-900">{{ title }}</h1>
      <p class="mt-1 text-sm text-slate-500">Ultimo aggiornamento: {{ formatDate(LEGAL.updatedAt) }}</p>
      <slot />
    </article>
    <LegalLinks class="mt-6" />
  </div>
</template>
