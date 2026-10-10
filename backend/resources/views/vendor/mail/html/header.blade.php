@props(['url', 'message' => null])
{{-- Logo allegato inline (CID): un URL remoto non si vede dove l'host non è raggiungibile o le immagini esterne sono bloccate. Senza $message (anteprima con ->render()) si ripiega sull'URL. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ $message ? $message->embed(resource_path('images/mail-logo.png')) : rtrim(config('app.frontend_url'), '/').'/icon-192.png' }}" width="32" height="32" alt="" style="width: 32px; height: 32px; vertical-align: middle; margin-right: 8px; border: 0;">
<span style="vertical-align: middle;">{!! $slot !!}</span>
</a>
</td>
</tr>
