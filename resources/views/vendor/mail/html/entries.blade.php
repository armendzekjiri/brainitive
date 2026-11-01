@props(['entries' => []])
{{-- Linked list lines of the notification email digest (BRAI-429). One HTML block without blank lines, so Markdown
     never parses it; every value is echoed escaped. --}}
<table class="entries" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($entries as $entry)
<tr>
<td class="entry">
<p class="entry-meta">{{ $entry['label'] }}@if (! empty($entry['time'])) · {{ $entry['time'] }}@endif</p>
<p class="entry-link"><a href="{{ $entry['url'] }}" target="_blank" rel="noopener">{{ $entry['link'] }}</a></p>
<p class="entry-text">{{ $entry['title'] }}@if (! empty($entry['context'])) <span class="entry-context">· {{ $entry['context'] }}</span>@endif</p>
@if (! empty($entry['body']))
<p class="entry-body">{{ $entry['body'] }}</p>
@endif
</td>
</tr>
@endforeach
</table>
