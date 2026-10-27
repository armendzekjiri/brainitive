@props(['url', 'label' => null])
<a href="{{ $url }}" class="break-all" target="_blank" rel="noopener">{{ $label ?? $url }}</a>
