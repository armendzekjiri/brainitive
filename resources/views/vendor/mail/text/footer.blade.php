@props([
    'audience' => 'account',
    'context' => null,
    'reason' => null,
    'links' => [],
])
--
Brainitive{{ filled($context) ? ' — '.$context : '' }}
@if (filled($reason))
{{ $reason }}
@endif
@if ($audience === 'member')
Manage notifications: {{ \App\Support\Mail\BrandedMail::appRoute('profile.edit') }}#notifications
@endif
@foreach ($links as $link)
{{ $link['label'] }}: {{ $link['url'] }}
@endforeach
