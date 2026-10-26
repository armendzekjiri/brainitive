{{-- Admin console test email (App\Mail\InstanceTestMail, BRAI-155). --}}
<x-mail::message preheader="If you can read this, outgoing mail works." audience="account" reason="You get this email because an instance admin sent a test from the admin console.">
<x-mail::headline>Outgoing mail works</x-mail::headline>

Hi there,

This is a test email from your Brainitive instance. If you can read this, outgoing mail works.

<x-mail::panel>
Sent via **{{ $transport }}**.
</x-mail::panel>

— Brainitive
</x-mail::message>
