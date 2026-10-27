@props(['text' => ''])
{{-- User-written text (comment, reply, note) in a panel. Never Markdown-parsed: escaped, line breaks as <br />, so no
     blank line can end the HTML block and let the rest of the text be read as Markdown (BRAI-155). --}}
<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="panel-content">
<p class="quote-text">{!! \App\Support\Mail\BrandedMail::htmlText((string) $text) !!}</p>
</td>
</tr>
</table>
