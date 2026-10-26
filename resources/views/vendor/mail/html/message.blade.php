@props([
    'preheader' => null,
    'audience' => 'account',
    'context' => null,
    'reason' => null,
    'footerLinks' => [],
    'raw' => false,
])
<x-mail::layout :preheader="$preheader" :raw="$raw">
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')" />
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer :audience="$audience" :context="$context ?? \App\Support\Mail\BrandedMail::instanceName()" :reason="$reason" :links="$footerLinks" />
</x-slot:footer>
</x-mail::layout>
