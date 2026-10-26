@props(['url' => null])
@php($base = rtrim((string) (config('app.url') ?: url('/')), '/'))
<tr>
<td class="header" bgcolor="#FFFFFF">
<a href="{{ $url ?: $base }}" target="_blank" rel="noopener">
<img src="{{ $base }}/images/email/logo.png" class="logo logo-light" width="220" height="61" alt="Brainitive" style="display: block; width: 220px; max-width: 220px; height: auto;" />
<!--[if !mso]><!-->
<div class="logo-dark-wrap">
<img src="{{ $base }}/images/email/logo-dark.png" class="logo logo-dark" width="220" height="66" alt="Brainitive" style="display: block; width: 220px; max-width: 220px; height: auto;" />
</div>
<!--<![endif]-->
</a>
</td>
</tr>
