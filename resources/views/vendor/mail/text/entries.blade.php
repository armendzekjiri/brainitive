@props(['entries' => []])
@foreach ($entries as $entry)
{{ $entry['label'] }}{{ ! empty($entry['time']) ? ' · '.$entry['time'] : '' }}
{{ $entry['link'] }}
{{ $entry['title'] }}{{ ! empty($entry['context']) ? ' · '.$entry['context'] : '' }}
@if (! empty($entry['body']))
{{ $entry['body'] }}
@endif
{{ $entry['url'] }}

@endforeach
