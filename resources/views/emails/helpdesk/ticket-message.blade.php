{{-- HTML part of a helpdesk mailbox email (App\Support\Helpdesks\Mail\TicketMailer, BRAI-155): an agent's reply or the
     acknowledgment of an email-created ticket. Raw layout (no Markdown): $bodyHtml is the reply's stored rich text,
     everything else is escaped. The text/plain part (what inbound threading reads) is built by TicketMailer. --}}
<x-mail::message :raw="true" audience="guest" :context="$sender" :reason="'You get this email because you have a ticket with '.$sender.'.'" :preheader="$preheader">
<p class="eyebrow">{{ $sender }} · {{ $ticketKey }}</p>
@if (filled($greeting ?? null))
<p>{{ $greeting }}</p>
@endif
<div class="message-body">{!! \App\Support\Mail\BrandedMail::rawHtml($bodyHtml) !!}</div>
@if ($signOff)
<p>— {{ $sender }}</p>
@endif
<x-slot:subcopy>
<p>{{ $ticketKey }} · <a href="{{ $ticketUrl }}" target="_blank" rel="noopener">View the ticket online</a><br />Reply to this email to answer.</p>
</x-slot:subcopy>
</x-mail::message>
