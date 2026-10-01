import { computed, ref, watch, type Ref } from 'vue'

// true se il form è stato modificato da quando la modale si è aperta.
export function useFormDirty(form: Ref<unknown>, open: Ref<boolean>) {
  const snapshot = ref('')
  watch(open, (v) => {
    if (v) snapshot.value = JSON.stringify(form.value)
  })
  return computed(() => open.value && JSON.stringify(form.value) !== snapshot.value)
}
