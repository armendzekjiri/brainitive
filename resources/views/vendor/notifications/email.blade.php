{{-- Every notification mail (BRAI-155). Extra keys come from App\Notifications\Messages\BrandedMailMessage::data();
     a plain MailMessage (no extras) still renders branded, with the generic "account" footer. --}}
<x-mail::message :preheader="$preheader ?? \App\Support\Mail\BrandedMail::preheaderFrom($introLines ?? [])" :audience="$audience ?? 'account'" :context="$context ?? null" :reason="$reason ?? null" :footer-links="$footerLinks ?? []">
@if (! empty($eyebrow))
<x-mail::eyebrow>{{ $eyebrow }}</x-mail::eyebrow>

@endif
@if (! empty($headline))
<x-mail::headline>{{ $headline }}</x-mail::headline>

@endif
{{-- Greeting (false = the text brings its own) --}}
@if (($greeting ?? null) !== false)
{{ ! empty($greeting) ? $greeting : 'Hi there,' }}
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Quoted user text (comment, reply, note) --}}
@if (! empty($quote))
<x-mail::quote :text="$quote" />

@endif
{{-- Action Button --}}
@isset($actionText)
@php($buttonColor = in_array($level ?? null, ['success', 'error'], true) ? $level : 'primary')
<x-mail::button :url="$actionUrl" :color="$buttonColor">
{{ $actionText }}
</x-mail::button>

@endisset
{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach
{{-- Sign-off --}}
{{ ! empty($salutation) ? $salutation : '— Brainitive' }}

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
Button not working? Paste this link into your browser: <x-mail::link :url="$actionUrl" />
</x-slot:subcopy>
@endisset
</x-mail::message>
