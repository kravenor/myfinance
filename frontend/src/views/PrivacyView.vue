<script setup lang="ts">
import LegalPage from '@/components/LegalPage.vue'
import { LEGAL } from '@/lib/legal'
</script>

<template>
  <LegalPage title="Informativa sulla privacy">
    <p>
      Questa pagina spiega quali dati personali tratta Finance, perché, per quanto tempo e quali diritti hai
      (artt. 13 e 14 del Regolamento UE 2016/679, GDPR).
    </p>

    <h2>Titolare del trattamento</h2>
    <p>
      {{ LEGAL.owner }} — contatto: <a :href="`mailto:${LEGAL.email}`">{{ LEGAL.email }}</a>. A questo indirizzo puoi
      scrivere per qualsiasi domanda sui tuoi dati o per esercitare i tuoi diritti.
    </p>

    <h2>Quali dati trattiamo</h2>
    <ul>
      <li><strong>Dati dell'account</strong>: nome, indirizzo email e password, conservata solo in forma cifrata (hash).</li>
      <li><strong>Preferenze</strong>: valuta, lingua, formato data, giorno di inizio mese, preferenze delle notifiche.</li>
      <li>
        <strong>Dati finanziari che inserisci tu</strong>: conti, transazioni, categorie, tag, budget, obiettivi,
        ricorrenti, investimenti, scenari e file che importi.
      </li>
      <li><strong>Dati tecnici di sessione</strong>: indirizzo IP e tipo di browser, legati alla sessione di accesso.</li>
    </ul>

    <h2>Perché e su quale base</h2>
    <ul>
      <li>
        Per fornirti il servizio che hai richiesto registrandoti (art. 6.1.b GDPR, esecuzione del contratto): senza nome,
        email e password non è possibile creare l'account.
      </li>
      <li>
        Per la sicurezza del servizio e la prevenzione degli abusi (art. 6.1.f, legittimo interesse): dati tecnici di
        sessione e limiti ai tentativi di accesso.
      </li>
      <li>Non usiamo i tuoi dati per pubblicità o profilazione e non li vendiamo a nessuno.</li>
    </ul>
    <p>I dati finanziari sono facoltativi: li inserisci tu, e sei tu a decidere cosa registrare.</p>

    <h2>A chi arrivano i dati</h2>
    <ul>
      <li>Al fornitore di hosting che ospita l'applicazione e il database ({{ LEGAL.hosting }}).</li>
      <li>
        Al fornitore del servizio email ({{ LEGAL.mail }}), solo per inviarti email che riguardano il tuo account:
        reimpostazione della password e avvisi su budget e obiettivi, se li hai attivati.
      </li>
      <li>
        Se attivi le notifiche su un dispositivo, al servizio push del tuo browser (Google per Chrome, Edge e Android,
        Mozilla per Firefox, Apple per Safari e iOS, Microsoft per Windows), che le consegna. Il contenuto viaggia
        cifrato e quel servizio non può leggerlo; vede solo l'indirizzo tecnico del dispositivo. Puoi disattivarle in
        qualsiasi momento da Impostazioni.
      </li>
    </ul>
    <p>
      Per tassi di cambio e quotazioni il server interroga fonti pubbliche di dati di mercato (Frankfurter/BCE, Yahoo
      Finance, CoinGecko, Borsa Italiana, Teleborsa). A queste fonti arrivano solo codici di valute e di strumenti
      finanziari (es. un ticker o un ISIN), mai dati che ti identificano. Il tuo browser non contatta altri servizi esterni, salvo il servizio push se attivi le notifiche.
    </p>

    <h2>Per quanto tempo</h2>
    <ul>
      <li>Dati dell'account e dati finanziari: finché l'account esiste, poi vengono cancellati.</li>
      <li>Sessione di accesso: scade dopo 120 minuti di inattività o quando esci.</li>
      <li>Accesso con «Ricordami»: fino a 400 giorni, o finché non esci.</li>
      <li>Link per reimpostare la password: valido 60 minuti.</li>
    </ul>

    <h2>I tuoi diritti</h2>
    <p>
      Puoi chiedere in qualsiasi momento di accedere ai tuoi dati, correggerli, cancellarli, limitarne il trattamento
      o opporti, scrivendo al titolare. Molti dati li puoi già correggere o cancellare da solo nell'app.
      Per la portabilità puoi esportare le transazioni in CSV da <strong>Import / Export</strong>.
      La cancellazione dell'account si richiede via email. Hai anche il diritto di presentare reclamo al
      <a href="https://www.garanteprivacy.it" target="_blank" rel="noopener noreferrer">Garante per la protezione dei dati personali</a>.
    </p>

    <h2>Sicurezza</h2>
    <p>
      Password cifrate, cookie di sessione non accessibili agli script della pagina, protezione dalle richieste
      contraffatte (CSRF), connessione cifrata (HTTPS) e dati di ogni utente separati da quelli degli altri.
    </p>

    <h2>Cookie</h2>
    <p>
      Finance usa solo cookie tecnici, necessari al funzionamento. I dettagli sono nella
      <RouterLink :to="{ name: 'cookie' }">cookie policy</RouterLink>.
    </p>
  </LegalPage>
</template>
