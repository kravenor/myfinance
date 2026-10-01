<script setup lang="ts">
import { computed } from 'vue'
import AppModal from '@/components/ui/AppModal.vue'
import { pendingConfirm, settleConfirm } from '@/composables/useConfirm'

const open = computed({
  get: () => pendingConfirm.value !== null,
  set: (v) => {
    if (!v) settleConfirm(false)
  },
})
</script>

<template>
  <AppModal v-model="open" :title="pendingConfirm?.title ?? ''" size="sm">
    <div v-if="pendingConfirm" class="space-y-2 px-4 py-4 sm:px-6">
      <p class="text-sm text-slate-800">{{ pendingConfirm.message }}</p>
      <p v-if="pendingConfirm.detail" class="text-sm text-slate-500">{{ pendingConfirm.detail }}</p>
    </div>
    <!-- Annulla a sinistra e con il focus iniziale: il distruttivo non è mai preselezionato. -->
    <div class="modal-footer">
      <button type="button" class="btn-secondary" autofocus @click="settleConfirm(false)">Annulla</button>
      <button
        type="button"
        :class="pendingConfirm?.danger ? 'btn-danger' : 'btn-primary'"
        @click="settleConfirm(true)"
      >
        {{ pendingConfirm?.confirmLabel }}
      </button>
    </div>
  </AppModal>
</template>
