{{-- ponytail: la parte solo testo usa la stessa vista, quindi mostra anche i tag <span>; vista text separata se serve --}}
<x-mail::message :message="$message ?? null">
# Ciao{{ $name ? ' '.$name : '' }},

ecco come è andato {{ $month }}.

<x-mail::table>
| Voce | Importo |
|:-----|--------:|
| Entrate | <span style="color: #15803d; font-weight: bold;">{{ $income }}</span> |
| Uscite | <span style="color: #b91c1c; font-weight: bold;">{{ $expense }}</span> |
| Risparmio | <span style="color: {{ $netPositive ? '#15803d' : '#b91c1c' }}; font-weight: bold;">{{ $net }}</span> |
</x-mail::table>

@if ($trend)
{{ $trend }}

@endif
<x-mail::button :url="$url">
Apri il report
</x-mail::button>

A presto,<br>
{{ config('app.name') }}

<x-slot:subcopy>
Se il pulsante non funziona, copia e incolla questo indirizzo nel browser: <span class="break-all">{{ $url }}</span>
</x-slot:subcopy>
</x-mail::message>
