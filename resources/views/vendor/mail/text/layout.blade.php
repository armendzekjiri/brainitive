@props(['preheader' => null, 'raw' => false])
{!! strip_tags($header ?? '') !!}

{{-- **bold** from the Markdown body reads as plain words in the text part --}}
{!! str_replace('**', '', strip_tags($slot)) !!}
@isset($subcopy)

{!! strip_tags($subcopy) !!}
@endisset

{!! strip_tags($footer ?? '') !!}
