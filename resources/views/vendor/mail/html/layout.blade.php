@props([
    'preheader' => null,
    'raw' => false,
])
{{-- Brainitive mail shell (BRAI-155): preheader, white card with the logo header, footer. Styles come from
     themes/brainitive.css (inlined); this <style> block only holds what can't be inlined: mobile + dark mode. --}}
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>Brainitive</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="x-apple-disable-message-reformatting" />
<meta name="color-scheme" content="light dark" />
<meta name="supported-color-schemes" content="light dark" />
<style>
:root { color-scheme: light dark; supported-color-schemes: light dark; }

@media only screen and (max-width: 600px) {
.wrapper-cell { padding: 16px 8px !important; }
.header { padding: 22px 22px 4px !important; }
.content-cell { padding: 20px 22px 28px !important; }
.footer-cell { padding: 20px 16px 8px !important; }
}

@media only screen and (max-width: 480px) {
.button { display: block !important; text-align: center !important; }
h1 { font-size: 20px !important; }
}

@media (prefers-color-scheme: dark) {
body, .wrapper, .wrapper-cell { background-color: #0F161D !important; }
.inner-body { background-color: #17212B !important; border-color: #263442 !important; }
.header, .content-cell { background-color: #17212B !important; }
.logo-light { display: none !important; max-height: 0 !important; }
.logo-dark-wrap { display: block !important; max-height: none !important; overflow: visible !important; }
h1, h2, h3, strong, .table th { color: #F1F5F8 !important; }
p, li, ul, ol, .message-body, .panel-content, .panel-content p, .table td { color: #D3DCE4 !important; }
.eyebrow, .subcopy p, .footer p, .footer a { color: #9AA9B6 !important; }
.subcopy { border-top-color: #263442 !important; }
.table th, .table td { border-bottom-color: #263442 !important; }
a { color: #5AB0F0 !important; }
.panel { background-color: #1E2A36 !important; }
.entry { border-bottom-color: #263442 !important; }
.entry-meta, .entry-context, .entry-body { color: #9AA9B6 !important; }
a.button { color: #FFFFFF !important; }
}

[data-ogsc] .logo-light { display: none !important; max-height: 0 !important; }
[data-ogsc] .logo-dark-wrap { display: block !important; max-height: none !important; overflow: visible !important; }
</style>
{!! $head ?? '' !!}
</head>
<body class="brainitive-mail">
@if (filled($preheader))
<div class="preheader">{{ $preheader }}&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
@endif

<table class="wrapper" data-brand="brainitive" width="100%" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#F4F6F8">
<tr>
<td class="wrapper-cell" align="center">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0"><tr><td><![endif]-->
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<table class="inner-body" width="100%" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#FFFFFF">
<tr>
<td class="accent" height="4" bgcolor="#0E83DC">&nbsp;</td>
</tr>
{!! $header ?? '' !!}

<!-- Body content -->
<tr>
<td class="content-cell">
@if ($raw)
{!! $slot !!}
@else
{!! Illuminate\Mail\Markdown::parse($slot) !!}
@endif

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td>
</tr>
</table>
</body>
</html>
