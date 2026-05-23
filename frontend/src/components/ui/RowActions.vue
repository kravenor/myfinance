<script setup lang="ts">
defineEmits<{
  (e: 'edit'): void
  (e: 'delete'): void
}>()

withDefaults(
  defineProps<{
    editTitle?: string
    deleteTitle?: string
    disabled?: boolean
  }>(),
  {
    editTitle: 'Modifica',
    deleteTitle: 'Elimina',
    disabled: false,
  },
)
</script>

<template>
  <div class="inline-flex items-center gap-1 justify-end">
    <button
      type="button"
      class="icon-btn icon-btn-edit"
      :title="editTitle"
      :aria-label="editTitle"
      :disabled="disabled"
      @click="$emit('edit')"
    >
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
        <path d="M13.586 3.586a2 2 0 1 1 2.828 2.828l-.793.793-2.828-2.828.793-.793Z" />
        <path d="m11.379 5.793-7.586 7.586a1 1 0 0 0-.263.464l-1 4a1 1 0 0 0 1.213 1.213l4-1a1 1 0 0 0 .464-.263l7.586-7.586-4.414-4.414Z" />
      </svg>
    </button>
    <button
      type="button"
      class="icon-btn icon-btn-delete"
      :title="deleteTitle"
      :aria-label="deleteTitle"
      :disabled="disabled"
      @click="$emit('delete')"
    >
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
        <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443a48.7 48.7 0 0 0-3.722.387.75.75 0 1 0 .244 1.48l.04-.005.43 9.46A3 3 0 0 0 5.99 18.5h8.02a3 3 0 0 0 2.998-2.985l.43-9.46.04.005a.75.75 0 1 0 .244-1.48 48.7 48.7 0 0 0-3.722-.387V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325c.827-.05 1.66-.075 2.5-.075ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 7.5a.75.75 0 1 0 1.5-.06l-.3-7.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 7.5a.75.75 0 1 0 1.5.06l.3-7.5Z" clip-rule="evenodd" />
      </svg>
    </button>
  </div>
</template>
