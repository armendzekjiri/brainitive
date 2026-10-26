@props([
    'audience' => 'account',
    'context' => null,
    'reason' => null,
    'links' => [],
])
{{-- member: "Manage notifications" (Profile → Notifications); guest / account: why they got it, never a preferences link --}}
<tr>
<td>
<table class="footer" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="footer-cell" align="center">
<p class="footer-brand">Brainitive{{ filled($context) ? ' — '.$context : '' }}</p>
@if (filled($reason))
<p class="footer-reason">{{ $reason }}</p>
@endif
@if ($audience === 'member')
<p><a href="{{ \App\Support\Mail\BrandedMail::appRoute('profile.edit') }}#notifications" target="_blank" rel="noopener">Manage notifications</a></p>
@endif
@foreach ($links as $link)
<p><a href="{{ $link['url'] }}" target="_blank" rel="noopener">{{ $link['label'] }}</a></p>
@endforeach
</td>
</tr>
</table>
</td>
</tr>
