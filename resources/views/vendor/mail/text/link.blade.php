@props(['url', 'label' => null])
{{ $label ? $label.': ' : '' }}{{ $url }}
