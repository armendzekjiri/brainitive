{{--
    Billing document (v1.38.0): a time report or an invoice, rendered by App\Support\Billing\BillingDocument
    with dompdf (A4, DejaVu Sans, no remote resources). Every value is pre-formatted there and escaped here;
    the logo is a data URI. Page numbers are drawn on the canvas after rendering.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{{ $kindLabel }}{{ $number ? ' '.$number : '' }} · {{ $client['name'] ?? '' }}</title>
<style>
    @page { margin: 16mm 15mm 24mm 15mm; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.6pt; line-height: 1.4; color: #1f2933; margin: 0; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }
    .muted { color: #66717d; }
    .small { font-size: 7.6pt; }
    .right { text-align: right; }
    .nowrap { white-space: nowrap; }

    .head td { padding: 0; }
    .logo { max-height: 56px; max-width: 220px; }
    .issuer-name { font-size: 12pt; font-weight: bold; }
    .issuer { text-align: right; }

    .sender { font-size: 6.8pt; color: #66717d; border-bottom: 0.6px solid #c7ced6; padding-bottom: 2px; margin-bottom: 6px; }
    .address-block { padding-top: 26px; width: 55%; }
    .client-name { font-size: 10pt; font-weight: bold; }
    .meta { width: 45%; padding-top: 26px; padding-left: 30px; }
    .meta table td { padding: 1.5px 0; }
    .meta table td.label { color: #66717d; padding-right: 10px; white-space: nowrap; }

    h1 { font-size: 16pt; margin: 26px 0 2px 0; font-weight: bold; }
    .subtitle { margin: 0 0 6px 0; color: #66717d; }
    .stamp { border: 1px solid #b42318; color: #b42318; font-size: 8pt; font-weight: bold; padding: 1px 6px; letter-spacing: 1px; }

    .group { margin-top: 14px; }
    .group-title { font-size: 9.6pt; font-weight: bold; padding: 4px 0; border-bottom: 1.2px solid #1f2933; }
    .group-title .key { color: #66717d; font-weight: normal; }
    .lines th { font-size: 7pt; text-transform: uppercase; letter-spacing: 0.4px; color: #66717d; font-weight: bold; text-align: left; padding: 5px 6px 4px 0; border-bottom: 0.6px solid #c7ced6; }
    .lines th.right { text-align: right; }
    .lines td { padding: 5px 6px 5px 0; border-bottom: 0.6px solid #e3e7eb; }
    .lines th:last-child, .lines td:last-child { padding-right: 0; }
    .lines { table-layout: fixed; }
    .lines tr { page-break-inside: avoid; }
    .lines .ticket-key { color: #0E83DC; font-weight: bold; white-space: nowrap; }
    .lines .notes { margin: 2px 0 0 0; padding: 0 0 0 10px; color: #66717d; font-size: 7.6pt; }
    .lines .billed { color: #66717d; font-size: 7.4pt; font-style: italic; }
    .lines tr.subtotal td { border-bottom: none; font-weight: bold; padding-top: 6px; }

    .totals-wrap { margin-top: 16px; page-break-inside: avoid; }
    .totals { width: 46%; margin-left: 54%; }
    .totals td { padding: 3px 0; }
    .totals tr.grand td { border-top: 1.2px solid #1f2933; font-size: 10.5pt; font-weight: bold; padding-top: 6px; }

    .payment { margin-top: 22px; page-break-inside: avoid; }
    .payment h2 { font-size: 8.6pt; margin: 0 0 4px 0; }
    .payment td { padding: 1px 0; }
    .payment td.label { color: #66717d; width: 70px; }

    footer { position: fixed; bottom: -17mm; left: 0; right: 0; height: 12mm; font-size: 7pt; color: #66717d; border-top: 0.6px solid #c7ced6; padding-top: 4px; padding-right: 90px; }
</style>
</head>
<body>

<footer>
    @if (filled($issuer['footer'] ?? null))
        {!! nl2br(e($issuer['footer'])) !!}
    @else
        {{ collect([$issuer['company_name'] ?? null, filled($issuer['vat_id'] ?? null) ? 'VAT ID '.$issuer['vat_id'] : null, $issuer['email'] ?? null])->filter()->implode(' · ') }}
    @endif
</footer>

<table class="head">
    <tr>
        <td style="width: 50%;">
            @if ($logo)
                <img class="logo" src="{{ $logo }}" alt="">
            @elseif (filled($issuer['company_name'] ?? null))
                <div class="issuer-name">{{ $issuer['company_name'] }}</div>
            @endif
        </td>
        <td class="issuer small" style="width: 50%;">
            @if ($logo && filled($issuer['company_name'] ?? null))
                <strong>{{ $issuer['company_name'] }}</strong><br>
            @endif
            @if (filled($issuer['address'] ?? null))
                {!! nl2br(e($issuer['address'])) !!}<br>
            @endif
            @if (filled($issuer['email'] ?? null)){{ $issuer['email'] }}<br>@endif
            @if (filled($issuer['phone'] ?? null)){{ $issuer['phone'] }}<br>@endif
            @if (filled($issuer['vat_id'] ?? null))<span class="muted">VAT ID</span> {{ $issuer['vat_id'] }}@endif
        </td>
    </tr>
</table>

<table>
    <tr>
        <td class="address-block">
            @if (filled($issuer['company_name'] ?? null))
                <div class="sender">{{ $issuer['company_name'] }}@if (filled($issuer['address'] ?? null)) · {{ preg_replace('/\s*\R\s*/', ', ', trim($issuer['address'])) }}@endif</div>
            @endif
            <div class="client-name">{{ $client['name'] ?? '' }}</div>
            @if (filled($client['address'] ?? null))
                <div>{!! nl2br(e($client['address'])) !!}</div>
            @endif
            @if (filled($client['vat_id'] ?? null))
                <div class="small muted" style="margin-top: 4px;">VAT ID {{ $client['vat_id'] }}</div>
            @endif
        </td>
        <td class="meta">
            <table>
                @if ($number)
                    <tr><td class="label">{{ $kind === 'invoice' ? 'Invoice no.' : 'Number' }}</td><td class="right">{{ $number }}</td></tr>
                @endif
                @if ($issued)
                    <tr><td class="label">Issue date</td><td class="right">{{ $issued }}</td></tr>
                @endif
                @if ($period[0] || $period[1])
                    <tr><td class="label">Period</td><td class="right">{{ $period[0] }} – {{ $period[1] }}</td></tr>
                @endif
                @if ($due)
                    <tr><td class="label">Due date</td><td class="right">{{ $due }}</td></tr>
                @endif
                <tr><td class="label">Currency</td><td class="right">{{ $currency }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<h1>{{ $kind === 'invoice' ? 'Invoice'.($number ? ' '.$number : '') : $title }}</h1>
@if ($status === 'draft')
    <p class="subtitle"><span class="stamp">DRAFT</span> Preview — amounts can still change until the report is finalized.</p>
@elseif ($status === 'void')
    <p class="subtitle"><span class="stamp">VOID</span> This document was cancelled.</p>
@endif
<p class="subtitle" style="margin-bottom: 14px;">
    @if ($kind === 'invoice'){{ $title }} · @endif{{ 'Time worked' }}@if ($period[0]) from {{ $period[0] }} to {{ $period[1] }}@endif @if ($rounding > 0)(rounded to {{ $rounding }} minutes per entry)@endif
</p>

@forelse ($groups as $group)
    <div class="group">
        <div class="group-title">{{ $group['name'] }} @if ($group['key'])<span class="key">{{ $group['key'] }}</span>@endif</div>
        <table class="lines">
            <thead>
                <tr>
                    {{-- fixed widths, so every project's table lines up --}}
                    <th style="width: {{ $showPeople ? 33 : 47 }}%;">Ticket</th>
                    <th style="width: 17%;">Activity</th>
                    @if ($showPeople)<th style="width: 14%;">Person</th>@endif
                    <th class="right" style="width: 8%;">Hours</th>
                    <th class="right" style="width: 15%;">Rate</th>
                    <th class="right" style="width: 13%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($group['lines'] as $line)
                    <tr>
                        <td>
                            @if ($line['key'])<span class="ticket-key">{{ $line['key'] }}</span> @endif{{ $line['title'] }}
                            @if ($line['already_billed'])<div class="billed">Fixed price, already billed</div>@endif
                            @if (count($line['notes']))
                                <div class="notes">@foreach ($line['notes'] as $note)– {{ $note }}@if (! $loop->last)<br>@endif @endforeach</div>
                            @endif
                        </td>
                        <td>{{ $line['activity'] }}</td>
                        @if ($showPeople)<td>{{ $line['person'] ?? '—' }}</td>@endif
                        <td class="right nowrap">{{ $line['hours'] }}</td>
                        <td class="right nowrap">{{ $line['rate'] }}</td>
                        <td class="right nowrap">{{ $line['amount'] }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal">
                    <td colspan="{{ $showPeople ? 3 : 2 }}">Subtotal {{ $group['name'] }}</td>
                    <td class="right nowrap">{{ $group['hours'] }}</td>
                    <td></td>
                    <td class="right nowrap">{{ $group['net'] }}</td>
                </tr>
            </tbody>
        </table>
    </div>
@empty
    <p class="muted">No billable time in this period.</p>
@endforelse

<div class="totals-wrap">
    <table class="totals">
        <tr><td class="muted">Total hours</td><td class="right">{{ $totals['hours'] }}</td></tr>
        <tr><td>Net</td><td class="right">{{ $totals['net'] }}</td></tr>
        <tr><td>{{ $pricesIncludeTax ? 'Included VAT' : 'VAT' }} {{ $taxPercent }} %</td><td class="right">{{ $totals['tax'] }}</td></tr>
        <tr class="grand"><td>{{ $pricesIncludeTax ? 'Total (incl. VAT)' : 'Total' }}</td><td class="right">{{ $totals['gross'] }}</td></tr>
    </table>
</div>

@if (filled($issuer['iban'] ?? null) || filled($issuer['bank_name'] ?? null) || $due)
    <div class="payment">
        <h2>Payment</h2>
        @if ($due)
            <p style="margin: 0 0 4px 0;">Please pay {{ $totals['gross'] }} by {{ $due }}@if ($terms) ({{ $terms }} days)@endif{{ $number ? ', quoting '.$number : '' }}.</p>
        @endif
        <table style="width: auto;">
            @if (filled($issuer['bank_name'] ?? null))<tr><td class="label">Bank</td><td>{{ $issuer['bank_name'] }}</td></tr>@endif
            @if (filled($issuer['company_name'] ?? null) && filled($issuer['iban'] ?? null))<tr><td class="label">Account</td><td>{{ $issuer['company_name'] }}</td></tr>@endif
            @if (filled($issuer['iban'] ?? null))<tr><td class="label">IBAN</td><td>{{ trim(chunk_split((string) $issuer['iban'], 4, ' ')) }}</td></tr>@endif
            @if (filled($issuer['bic'] ?? null))<tr><td class="label">BIC</td><td>{{ $issuer['bic'] }}</td></tr>@endif
        </table>
    </div>
@endif

</body>
</html>
