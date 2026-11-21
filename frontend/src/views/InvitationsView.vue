<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import ListSkeleton from '@/components/ui/ListSkeleton.vue'
import AppModal from '@/components/ui/AppModal.vue'
import FormErrors from '@/components/ui/FormErrors.vue'
import FieldError from '@/components/ui/FieldError.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import { useCrud } from '@/composables/useCrud'
import { confirmAction } from '@/composables/useConfirm'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/date'
import { INVITATION_STATUS_LABEL } from '@/lib/labels'
import { useToastStore } from '@/stores/toast'
import type { Invitation } from '@/types/api'

type Mode = 'email' | 'generic'

const { items, loading, submitting, fieldErrors, list, destroy } = useCrud<Invitation>('invitations')
const toast = useToastStore()

const showForm = ref(false)
const form = ref<{ mode: Mode; email: string; send_email: boolean }>({ mode: 'email', email: '', send_email: true })
const modeOptions: { value: Mode; label: string }[] = [
  { value: 'email', label: 'Per un indirizzo' },
  { value: 'generic', label: 'Link generico' },
]

// Il link in chiaro arriva solo nella risposta di creazione: dopo non è più recuperabile.
const created = ref<{ url: string; email: string | null; emailSent: boolean } | null>(null)
const showCreated = computed({
  get: () => created.value !== null,
  set: (open: boolean) => {
    if (!open) created.value = null
  },
})

function openForm() {
  fieldErrors.value = {}
  form.value = { mode: 'email', email: '', send_email: true }
  showForm.value = true
}

async function onSubmit() {
  const bound = form.value.mode === 'email'
  submitting.value = true
  fieldErrors.value = {}
  try {
    const { data } = await api.post<{ data: Invitation; url: string; email_sent: boolean }>('/invitations', {
      email: bound ? form.value.email : null,
      send_email: bound && form.value.send_email,
    })
    items.value.unshift(data.data)
    showForm.value = false
    created.value = { url: data.url, email: data.data.email, emailSent: data.email_sent }
  } catch (e: unknown) {
    const err = e as { response?: { status?: number; data?: { errors?: Record<string, string[]> } } }
    if (err.response?.status === 422) fieldErrors.value = err.response.data?.errors ?? {}
  } finally {
    submitting.value = false
  }
}

async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    toast.success('Link copiato.')
  } catch {
    toast.error('Copia non riuscita: seleziona il link a mano.')
  }
}

async function onRevoke(inv: Invitation) {
  const pending = inv.status === 'pending'
  const target = inv.email ?? 'link generico'
  const question = pending
    ? `Revocare l'invito (${target})? Il link smetterà subito di funzionare.`
    : `Togliere dalla lista l'invito (${target})?`
  if (!(await confirmAction(question))) return
  await destroy(inv.id)
  toast.success(pending ? 'Invito revocato.' : 'Invito rimosso.')
}

const STATUS_CLASS: Record<Invitation['status'], string> = {
  pending: 'bg-primary-100 text-primary-700',
  accepted: 'bg-income-100 text-income-700',
  expired: 'bg-slate-100 text-slate-600',
}

onMounted(() => list())
</script>

<template>
  <div class="space-y-4 pb-20 lg:pb-0">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold">Inviti</h1>
        <p class="page-desc">Link per far creare un account a persone che scegli tu, anche con la registrazione chiusa.</p>
      </div>
      <button class="btn-primary" @click="openForm">Nuovo invito</button>
    </div>

    <details class="help-panel">
      <summary>Come funziona</summary>
      <ul>
        <li>Ogni link vale per una sola registrazione e scade dopo 7 giorni.</li>
        <li>
          «Per un indirizzo» accetta solo quell'email: anche se il link viene inoltrato, nessun altro può usarlo. Il «link
          generico» va bene per chiunque lo riceva, quindi mandalo solo a chi conosci.
        </li>
        <li>Il link compare una volta sola, appena creato: se lo perdi, revoca l'invito e creane un altro.</li>
        <li>Chi si registra ha un account suo, separato: non vede i tuoi dati e non può invitare altri.</li>
      </ul>
    </details>

    <AppModal v-slot="{ close }" v-model="showForm" title="Nuovo invito">
      <form @submit.prevent="onSubmit">
        <div class="grid grid-cols-1 gap-4 px-4 py-4 sm:px-6">
          <FormErrors :errors="fieldErrors" :shown="['email']" />
          <div>
            <span class="label">Tipo di invito</span>
            <SegmentedControl v-model="form.mode" :options="modeOptions" label="Tipo di invito" />
          </div>
          <template v-if="form.mode === 'email'">
            <div>
              <label class="label" for="invite-email">Email dell'invitato</label>
              <input
                id="invite-email"
                v-model="form.email"
                type="email"
                class="input"
                :class="{ 'input-invalid': fieldErrors.email }"
                required
                maxlength="255"
                aria-describedby="hint-invite-email"
              />
              <p id="hint-invite-email" class="field-hint">La registrazione accetterà solo questo indirizzo.</p>
              <FieldError :errors="fieldErrors" name="email" />
            </div>
            <label class="flex items-center gap-3">
              <input v-model="form.send_email" type="checkbox" class="w-4 h-4" />
              <span class="text-sm">Invia il link per email</span>
            </label>
          </template>
          <p v-else class="text-sm text-slate-600">
            Chi apre il link sceglie con quale email registrarsi. Lo copi tu e lo mandi come preferisci.
          </p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" @click="close">Annulla</button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            {{ submitting ? 'Creazione…' : 'Crea invito' }}
          </button>
        </div>
      </form>
    </AppModal>

    <AppModal v-slot="{ close }" v-model="showCreated" title="Invito creato">
      <div v-if="created" class="space-y-3 px-4 py-4 sm:px-6">
        <p v-if="created.emailSent" role="status" class="text-sm text-income-700">
          Email inviata a {{ created.email }}.
        </p>
        <p v-else-if="created.email" role="status" class="text-sm text-slate-600">
          Nessuna email inviata a {{ created.email }}: copia il link e mandalo tu. Se avevi chiesto l'invio, il server
          non ha un servizio di posta configurato o non è riuscito a raggiungerlo.
        </p>
        <p v-else class="text-sm text-slate-600">Copia il link e mandalo a chi vuoi invitare.</p>
        <label class="label" for="invite-url">Link di invito</label>
        <div class="flex gap-2">
          <input id="invite-url" :value="created.url" readonly class="input font-mono text-xs" @focus="($event.target as HTMLInputElement).select()" />
          <button type="button" class="btn-secondary shrink-0" @click="copy(created.url)">Copia</button>
        </div>
        <p class="field-hint">Non verrà più mostrato: dopo resta salvata solo un'impronta non reversibile.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-primary" @click="close">Fatto</button>
      </div>
    </AppModal>

    <div class="card table-responsive md:overflow-x-auto">
      <ListSkeleton v-if="loading && !items.length" />
      <table v-else class="table" :class="{ 'opacity-60': loading }">
        <thead class="bg-slate-100">
          <tr>
            <th>Destinatario</th>
            <th>Stato</th>
            <th>Creato</th>
            <th>Scadenza</th>
            <th></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="inv in items" :key="inv.id">
            <td data-label="Destinatario" class="font-medium">
              {{ inv.email ?? 'Link generico' }}
              <span v-if="inv.accepted_by && inv.accepted_by !== inv.email" class="block text-xs font-normal text-slate-500">
                usato da {{ inv.accepted_by }}
              </span>
            </td>
            <td data-label="Stato">
              <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="STATUS_CLASS[inv.status]">
                {{ INVITATION_STATUS_LABEL[inv.status] }}
              </span>
            </td>
            <td data-label="Creato">{{ formatDate(inv.created_at) }}</td>
            <td data-label="Scadenza">
              {{ inv.status === 'accepted' ? `usato il ${formatDate(inv.accepted_at)}` : formatDate(inv.expires_at) }}
            </td>
            <td class="md:text-right actions-cell">
              <button type="button" class="btn-secondary text-sm" @click="onRevoke(inv)">
                {{ inv.status === 'pending' ? 'Revoca' : 'Rimuovi' }}
              </button>
            </td>
          </tr>
          <tr v-if="items.length === 0">
            <td colspan="5" class="whitespace-normal">
              <EmptyState title="Non hai ancora creato inviti.">
                <button type="button" class="btn-primary" @click="openForm">Crea il primo invito</button>
              </EmptyState>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
