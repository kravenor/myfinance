<script setup lang="ts">
import LegalPage from '@/components/LegalPage.vue'

const COOKIES = [
  { name: 'finance_session', purpose: 'Mantiene l\'accesso mentre usi l\'app.', duration: '120 minuti di inattività, o fino all\'uscita' },
  { name: 'XSRF-TOKEN', purpose: 'Protegge dalle richieste contraffatte da altri siti (CSRF).', duration: 'Come la sessione' },
  { name: 'remember_web_…', purpose: 'Ti tiene connesso tra una visita e l\'altra, solo se scegli «Ricordami».', duration: 'Fino a 90 giorni, o fino all\'uscita' },
]
const STORAGE = [
  { name: 'theme', purpose: 'Tema scelto in Impostazioni (automatico, chiaro o scuro).' },
  { name: 'menu.hidden', purpose: 'Voci del menu che hai nascosto in Impostazioni.' },
]
</script>

<template>
  <LegalPage title="Cookie policy">
    <p>
      Un cookie è un piccolo file che il sito salva nel browser per riconoscerti tra una pagina e l'altra.
      Finance usa <strong>solo cookie tecnici di prima parte</strong>, indispensabili per accedere e lavorare in sicurezza:
      nessun cookie di profilazione, di statistica o di terze parti. Per questo non ti chiediamo un consenso e non trovi
      un banner (Linee guida del Garante privacy del 10 giugno 2021).
    </p>

    <h2>Cookie usati</h2>
    <div class="table-responsive mt-3 rounded-lg border border-slate-200">
      <table class="table">
        <thead class="bg-slate-100">
          <tr>
            <th>Nome</th>
            <th>A cosa serve</th>
            <th>Durata</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="c in COOKIES" :key="c.name">
            <td data-label="Nome" class="font-mono text-xs">{{ c.name }}</td>
            <td data-label="A cosa serve" class="!whitespace-normal">{{ c.purpose }}</td>
            <td data-label="Durata" class="!whitespace-normal">{{ c.duration }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <h2>Preferenze salvate nel browser</h2>
    <p>
      Oltre ai cookie, l'app salva nel browser (localStorage) due preferenze di interfaccia. Restano sul tuo dispositivo e
      non vengono inviate al server:
    </p>
    <ul>
      <li v-for="s in STORAGE" :key="s.name"><code class="text-xs">{{ s.name }}</code>: {{ s.purpose }}</li>
    </ul>

    <h2>Come gestirli</h2>
    <p>
      Puoi cancellare o bloccare i cookie dalle impostazioni del browser. Senza i cookie tecnici, però, non è possibile
      accedere all'app. Uscendo con «Esci» la sessione viene chiusa e il cookie «Ricordami» eliminato.
    </p>

    <h2>Altre informazioni</h2>
    <p>
      Per sapere come trattiamo i tuoi dati personali leggi l'<RouterLink :to="{ name: 'privacy' }">informativa sulla privacy</RouterLink>.
    </p>
  </LegalPage>
</template>
