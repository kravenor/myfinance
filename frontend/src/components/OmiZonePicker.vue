<script setup lang="ts">
import axios from 'axios'
import { computed, onMounted, ref, watch } from 'vue'
import { api, ensureCsrf } from '@/lib/api'
import type { OmiImportResult, OmiSemester, OmiState, OmiZone } from '@/types/api'

// Zona OMI di un immobile: le quotazioni le carica l'utente (il file dell'Agenzia delle
// Entrate si scarica solo con SPID), poi qui sceglie zona, tipologia e stato.
const zone = defineModel<string>('zone', { required: true })
const type = defineModel<number | null>('type', { required: true })
const state = defineModel<OmiState | null>('state', { required: true })

const semesters = ref<OmiSemester[] | null>(null)
const query = ref('')
const zones = ref<OmiZone[]>([])
const searching = ref(false)
const uploading = ref(false)
const uploadMessage = ref('')
const uploadError = ref('')

const selected = computed(() => zones.value.find((z) => z.link_zona === zone.value) ?? null)
const typeKey = computed({
  get: () => (type.value && state.value ? `${type.value}|${state.value}` : ''),
  set: (key: string) => {
    const [cod, stato] = key.split('|')
    type.value = cod ? Number(cod) : null
    state.value = (stato as OmiState) || null
  },
})
// Zona scelta letta da sola (`zone=`): i suoi semestri sono completi, nella ricerca per comune no.
const detail = ref<OmiZone | null>(null)
const linked = computed(
  () => detail.value?.types.find((t) => t.cod_tip === type.value && t.stato === state.value)?.semesters ?? [],
)
// Semestri caricati in cui questa zona non c'è: file di altri comuni, o zone ridisegnate dall'Agenzia.
const unlinked = computed(() => (semesters.value ?? []).map((s) => s.semester).filter((s) => !linked.value.includes(s)).sort())

async function loadDetail() {
  detail.value = zone.value
    ? ((await api.get<{ data: OmiZone[] }>('/omi-quotations/zones', { params: { zone: zone.value } })).data.data[0] ?? null)
    : null
}
watch(zone, loadDetail)

const manyComuni = computed(() => new Set(zones.value.map((z) => z.comune)).size > 1)

function semesterLabel(date: string): string {
  const [year, month] = date.split('-')
  return `S${month === '01' ? 1 : 2} ${year}`
}

function zoneLabel(z: OmiZone): string {
  return [manyComuni.value ? z.comune : '', z.zona, z.zona_descr ? `· ${z.zona_descr}` : ''].filter(Boolean).join(' ')
}

async function loadSemesters() {
  semesters.value = (await api.get<{ data: OmiSemester[] }>('/omi-quotations')).data.data
}

async function search(params: Record<string, string>) {
  searching.value = true
  try {
    zones.value = (await api.get<{ data: OmiZone[] }>('/omi-quotations/zones', { params })).data.data
  } finally {
    searching.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | undefined
function onQuery() {
  clearTimeout(timer)
  if (query.value.trim().length < 2) return
  timer = setTimeout(() => search({ q: query.value.trim() }), 300)
}

// Zona nuova: si tiene la tipologia scelta se esiste anche lì, altrimenti abitazioni civili in stato normale.
watch(selected, (z) => {
  if (!z) {
    if (!zone.value) typeKey.value = ''
    return
  }
  if (z.types.some((t) => t.cod_tip === type.value && t.stato === state.value)) return
  const preferred = z.types.find((t) => t.cod_tip === 20 && t.stato === 'NORMALE') ?? z.types[0]
  typeKey.value = preferred ? `${preferred.cod_tip}|${preferred.stato}` : ''
})

async function onFile(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  uploading.value = true
  uploadMessage.value = ''
  uploadError.value = ''
  try {
    await ensureCsrf()
    const form = new FormData()
    form.append('file', file)
    const { data } = await api.post<{ data: OmiImportResult }>('/omi-quotations/import', form)
    uploadMessage.value = `${semesterLabel(data.data.semester)} caricato: ${data.data.rows} quotazioni di ${data.data.comuni} comuni.`
    await loadSemesters()
    await loadDetail()
    if (query.value.trim().length >= 2) await search({ q: query.value.trim() })
  } catch (err) {
    uploadError.value = (axios.isAxiosError(err) && err.response?.data?.errors?.file?.[0]) || 'Caricamento non riuscito.'
  } finally {
    uploading.value = false
    input.value = ''
  }
}

onMounted(async () => {
  await loadSemesters()
  if (zone.value) {
    await Promise.all([search({ zone: zone.value }), loadDetail()])
    query.value = selected.value?.comune ?? ''
  }
})
</script>

<template>
  <fieldset class="space-y-3">
    <legend class="label">Zona OMI <span class="font-normal text-slate-500">(facoltativa)</span></legend>

    <template v-if="semesters && semesters.length">
      <div class="grid gap-3 sm:grid-cols-3">
        <div>
          <label for="omi-comune" class="sr-only">Comune</label>
          <input id="omi-comune" v-model="query" class="input" placeholder="Comune, es. Torino" autocomplete="off" @input="onQuery" />
        </div>
        <div>
          <label for="omi-zone" class="sr-only">Zona</label>
          <select id="omi-zone" v-model="zone" class="input" :disabled="!zones.length && !zone">
            <option value="">{{ searching ? 'Cerco…' : zones.length ? 'Solo indice ISTAT' : 'Cerca il comune' }}</option>
            <option v-for="z in zones" :key="z.link_zona" :value="z.link_zona">{{ zoneLabel(z) }}</option>
          </select>
        </div>
        <div>
          <label for="omi-type" class="sr-only">Tipologia e stato</label>
          <select id="omi-type" v-model="typeKey" class="input" :disabled="!selected">
            <option v-if="!selected" value="">Tipologia e stato</option>
            <option v-for="t in selected?.types ?? []" :key="`${t.cod_tip}|${t.stato}`" :value="`${t.cod_tip}|${t.stato}`">
              {{ t.tipologia }} · {{ t.stato.toLowerCase() }}
            </option>
          </select>
        </div>
      </div>
      <div v-if="linked.length" class="text-sm rounded-sm bg-slate-50 p-3 space-y-1" role="status">
        <p>
          Serie della zona: <strong>{{ linked.length }} {{ linked.length === 1 ? 'semestre' : 'semestri' }}</strong>,
          <template v-if="linked.length > 1">da {{ semesterLabel(linked[0]) }} a {{ semesterLabel(linked[linked.length - 1]) }}.</template>
          <template v-else>{{ semesterLabel(linked[0]) }}.</template>
        </p>
        <p v-if="linked.length === 1" class="text-slate-600">
          Con un solo semestre l'andamento viene tutto dall'indice ISTAT: carica anche il semestre più vecchio disponibile, vicino alla data d'acquisto.
        </p>
        <p v-if="unlinked.length" class="text-warning-700">
          Non collegati: {{ unlinked.map(semesterLabel).join(', ') }}. In quei file questa zona non c'è: se sono di questo comune,
          probabilmente l'Agenzia ha ridisegnato le zone e lì ha un codice diverso, quindi per quei periodi vale l'indice ISTAT.
        </p>
      </div>
      <p class="field-hint">
        Con la zona il valore segue le quotazioni OMI del quartiere, semestre per semestre; prima e dopo i semestri caricati segue l'indice ISTAT.
        Caricati: {{ semesters.map((s) => semesterLabel(s.semester)).join(', ') }}.
      </p>
    </template>
    <p v-else-if="semesters" class="field-hint">
      Per seguire il tuo quartiere invece della media del territorio carica le quotazioni OMI: su agenziaentrate.gov.it, «Forniture dati OMI» →
      «Quotazioni immobiliari» (serve SPID), scarica il semestre del tuo comune e caricalo qui così com'è. Più semestri carichi, più lo storico è preciso.
    </p>

    <div class="flex flex-wrap items-center gap-3 text-sm">
      <label class="btn-secondary cursor-pointer" :class="{ 'opacity-50 pointer-events-none': uploading }">
        {{ uploading ? 'Caricamento…' : semesters?.length ? 'Carica un altro semestre' : 'Carica file OMI' }}
        <input type="file" accept=".zip,.csv" class="sr-only" :disabled="uploading" @change="onFile" />
      </label>
      <span v-if="uploadMessage" class="text-income-700" role="status">{{ uploadMessage }}</span>
      <span v-if="uploadError" class="text-danger-600" role="alert">{{ uploadError }}</span>
    </div>
  </fieldset>
</template>
