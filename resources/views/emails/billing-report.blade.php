{{-- Billing report mail to a client (App\Mail\BillingReportMail) on the branded layout (BRAI-155). The admin's message is plain text:
     escaped, line breaks kept, inside one HTML block so Markdown never formats it (keep it on one line, no blank lines). --}}
<x-mail::message :preheader="'Attached: '.$filename" audience="guest" :context="filled($company) ? $company : $sender" :reason="'You get this email because '.$sender.' sent you this document. Reply to reach them directly.'">
<x-mail::eyebrow>{{ filled($company) ? $company : $sender }}</x-mail::eyebrow>

@if (filled($body))
<div class="message-body">{!! \App\Support\Mail\BrandedMail::htmlText($body) !!}</div>
@else
<div class="message-body">Hello,<br><br>please find the document attached.</div>
@endif

<x-mail::panel>
Attached: **{{ $filename }}**
</x-mail::panel>

— {{ $sender }}{{ filled($company) ? ' · '.$company : '' }}

<x-slot:subcopy>
Reply to this email to reach {{ $sender }} directly.
</x-slot:subcopy>
</x-mail::message>
