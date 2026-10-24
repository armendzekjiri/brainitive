<?php

namespace App\Support\Billing;

use App\Models\BillingReport;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Renders a billing report's data (snapshot v1, docs/ai/tasks/release-v1.38.0-billing.md) as a PDF
 * (dompdf, resources/views/billing/document.blade.php) or an xlsx (openspout). It only formats what
 * the snapshot says — cents, minutes, basis points — and never recomputes an amount.
 */
class BillingDocument
{
    /** Shown as a prefix symbol; every other currency prints its ISO code. */
    private const SYMBOLS = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];

    /** @param array<string, mixed> $data */
    public function __construct(
        private readonly array $data,
        private readonly int $workspaceId,
        private readonly string $status = 'finalized',
    ) {}

    public static function fromReport(BillingReport $report): self
    {
        return new self(ReportData::for($report), $report->workspace_id, $report->status);
    }

    // ── formatting (locale-neutral: 1,234.50) ───────────────────────────────────────────────

    public static function money(int|float|null $cents, string $currency): string
    {
        $cents = (int) ($cents ?? 0);
        $amount = number_format(abs($cents) / 100, 2, '.', ',');
        $code = strtoupper($currency);
        $prefix = self::SYMBOLS[$code] ?? $code.' ';

        return ($cents < 0 ? '-' : '').$prefix.$amount;
    }

    public static function hours(int|float|null $minutes): string
    {
        return number_format(((int) ($minutes ?? 0)) / 60, 2, '.', ',');
    }

    /** 1900 → "19", 770 → "7.7", 0 → "0". */
    public static function percent(int|float|null $basisPoints): string
    {
        return rtrim(rtrim(number_format(((int) ($basisPoints ?? 0)) / 100, 2, '.', ''), '0'), '.');
    }

    /** Names on the document: the report's "show people" option, or grouping by person. */
    public function showsPeople(): bool
    {
        $options = (array) ($this->data['options'] ?? []);

        return (bool) ($options['show_people'] ?? false) || ($options['group_by'] ?? null) === 'person';
    }

    public function showsNotes(): bool
    {
        return (bool) data_get($this->data, 'options.show_notes', false);
    }

    /** Draft and void documents carry a stamp and never ask for payment. */
    public function isFinal(): bool
    {
        return ! in_array($this->status, ['draft', 'void'], true);
    }

    public function kind(): string
    {
        return ($this->data['kind'] ?? 'report') === 'invoice' ? 'invoice' : 'report';
    }

    public function kindLabel(): string
    {
        return $this->kind() === 'invoice' ? 'Invoice' : 'Time report';
    }

    /** `time-report-2026-10-muster-gmbh.pdf`, `invoice-2026-014-muster-gmbh.xlsx`. */
    public function filename(string $extension): string
    {
        $from = (string) data_get($this->data, 'period.from', '');
        $to = (string) data_get($this->data, 'period.to', '');
        $period = substr($from, 0, 7) === substr($to, 0, 7) ? substr($from, 0, 7) : $from.'-'.$to;
        $number = $this->kind() === 'invoice' ? (string) ($this->data['number'] ?? '') : '';

        $parts = array_filter([
            Str::slug($this->kindLabel()),
            Str::slug($number !== '' ? $number : $period),
            Str::slug((string) data_get($this->data, 'client.name', '')),
        ]);

        return Str::limit(implode('-', $parts), 120, '').'.'.$extension;
    }

    /** What the Blade view prints, all values pre-formatted (the view escapes them). */
    public function viewData(): array
    {
        $d = $this->data;
        $currency = (string) ($d['currency'] ?? 'EUR');
        $issuer = (array) ($d['issuer'] ?? []);
        $issued = $this->date($d['issued_on'] ?? null);
        $terms = $issuer['payment_terms_days'] ?? null;
        $showPeople = $this->showsPeople();
        $showNotes = $this->showsNotes();

        $groups = [];
        foreach ((array) ($d['groups'] ?? []) as $group) {
            $lines = [];
            foreach ((array) ($group['lines'] ?? []) as $line) {
                $fixed = data_get($line, 'activity.pricing_type') === 'fixed';
                $rate = self::money($line['rate_cents'] ?? 0, $currency);
                $lines[] = [
                    'key' => $line['item_key'] ?? null,
                    'title' => (string) (($line['item_title'] ?? null) ?: (($line['item_key'] ?? null) ? '' : 'No ticket')),
                    'activity' => (string) data_get($line, 'activity.name', ''),
                    'person' => $line['person'] ?? null,
                    'hours' => self::hours($line['billed_minutes'] ?? $line['minutes'] ?? 0),
                    'rate' => $fixed ? $rate.' fixed' : $rate.' / h',
                    'amount' => self::money($line['amount_cents'] ?? 0, $currency),
                    'already_billed' => (bool) ($line['fixed_already_billed'] ?? false),
                    'notes' => $showNotes ? array_values(array_filter(array_map('strval', (array) ($line['notes'] ?? [])))) : [],
                ];
            }
            $groups[] = [
                'name' => (string) data_get($group, 'project.name', ''),
                'key' => data_get($group, 'project.key'),
                'lines' => $lines,
                'hours' => self::hours($group['billed_minutes'] ?? $group['minutes'] ?? 0),
                'net' => self::money($group['net_cents'] ?? 0, $currency),
            ];
        }

        $totals = (array) ($d['totals'] ?? []);

        return [
            'status' => $this->status,
            'kind' => $this->kind(),
            'kindLabel' => $this->kindLabel(),
            'number' => $d['number'] ?? null,
            'title' => (string) (($d['title'] ?? null) ?: $this->kindLabel()),
            'issued' => $issued?->format('Y-m-d'),
            'due' => $this->isFinal() && $issued && is_numeric($terms) && (int) $terms > 0 ? $issued->addDays((int) $terms)->format('Y-m-d') : null,
            'terms' => is_numeric($terms) ? (int) $terms : null,
            'period' => [data_get($d, 'period.from'), data_get($d, 'period.to')],
            'currency' => $currency,
            'taxPercent' => self::percent($d['tax_rate_bp'] ?? 0),
            'pricesIncludeTax' => (bool) ($d['prices_include_tax'] ?? false),
            'rounding' => (int) ($d['rounding_minutes'] ?? 0),
            'issuer' => $issuer,
            'client' => (array) ($d['client'] ?? []),
            'logo' => BillingLogo::dataUri($issuer['logo_path'] ?? null, $this->workspaceId),
            'showPeople' => $showPeople,
            'groups' => $groups,
            'totals' => [
                'hours' => self::hours($totals['billed_minutes'] ?? $totals['minutes'] ?? 0),
                'net' => self::money($totals['net_cents'] ?? 0, $currency),
                'tax' => self::money($totals['tax_cents'] ?? 0, $currency),
                'gross' => self::money($totals['gross_cents'] ?? 0, $currency),
            ],
        ];
    }

    // ── PDF ─────────────────────────────────────────────────────────────────────────────────

    public function pdf(): string
    {
        // font metrics cache (DejaVu Sans ships with dompdf); under framework/cache, which git ignores
        $fonts = storage_path('framework/cache/dompdf');
        File::ensureDirectoryExists($fonts);

        $options = new Options([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            // the logo is a data URI; nothing else may be loaded (no file:// or http(s)://)
            'allowedProtocols' => ['data://' => ['rules' => []]],
            'chroot' => [resource_path('views/billing')],
            'fontDir' => $fonts,
            'fontCache' => $fonts,
            'tempDir' => sys_get_temp_dir(),
            'defaultFont' => 'DejaVu Sans',
            'defaultPaperSize' => 'a4',
            'dpi' => 96,
        ]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('billing.document', $this->viewData())->render(), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 100, $canvas->get_height() - 30, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.45, 0.47, 0.5]);

        return (string) $dompdf->output();
    }

    // ── Excel ───────────────────────────────────────────────────────────────────────────────

    /** The xlsx as bytes: sheet "Summary" (groups, lines, totals) and sheet "Entries" (the entries array). */
    public function xlsx(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'billing-xlsx-');
        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $this->writeSummary($writer);
            $writer->addNewSheetAndMakeItCurrent();
            $this->writeEntries($writer);
            $writer->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    private function writeSummary(Writer $writer): void
    {
        $d = $this->data;
        $currency = (string) ($d['currency'] ?? 'EUR');
        $bold = (new Style)->withFontBold(true);
        $money = (new Style)->withFormat('#,##0.00');
        $boldMoney = (new Style)->withFontBold(true)->withFormat('#,##0.00');
        $hours = (new Style)->withFormat('0.00');
        $boldHours = (new Style)->withFontBold(true)->withFormat('0.00');

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Summary');
        $sheet->setColumnWidth(16, 1);
        $sheet->setColumnWidth(12, 2);
        $sheet->setColumnWidth(40, 3);
        $sheet->setColumnWidth(18, 4, 5);
        $sheet->setColumnWidth(12, 6, 7);
        $sheet->setColumnWidth(14, 8, 9);
        $sheet->setColumnWidth(30, 10);

        $issued = $this->date($d['issued_on'] ?? null);
        $writer->addRow(new Row([self::text((string) (($d['title'] ?? null) ?: $this->kindLabel()), $bold)]));
        $meta = [
            'Status' => match ($this->status) {
                'draft' => 'DRAFT — amounts can still change until the report is finalized',
                'void' => 'VOID — this document was cancelled',
                default => null,
            },
            'Document' => $this->kindLabel(),
            'Number' => $d['number'] ?? null,
            'Client' => data_get($d, 'client.name'),
            'Period' => trim(data_get($d, 'period.from', '').' – '.data_get($d, 'period.to', ''), ' –'),
            'Issued' => $issued?->format('Y-m-d'),
            'Currency' => $currency,
            'Rounding' => ($d['rounding_minutes'] ?? 0) ? $d['rounding_minutes'].' min per entry' : 'exact',
        ];
        foreach ($meta as $label => $value) {
            if ($value !== null && $value !== '') {
                $writer->addRow(new Row([self::text($label, $bold), self::text((string) $value)]));
            }
        }
        $writer->addRow(new Row([]));

        $writer->addRow(new Row(array_map(fn ($h) => self::text($h, $bold), [
            'Project', 'Ticket', 'Title', 'Activity', 'Person', 'Hours', 'Billed hours', "Rate ($currency)", "Amount ($currency)", 'Note',
        ])));

        foreach ((array) ($d['groups'] ?? []) as $group) {
            $project = trim(data_get($group, 'project.key', '').' '.data_get($group, 'project.name', ''));
            foreach ((array) ($group['lines'] ?? []) as $line) {
                $fixed = data_get($line, 'activity.pricing_type') === 'fixed';
                $note = ($line['fixed_already_billed'] ?? false) ? 'Fixed price, already billed' : ($fixed ? 'Fixed price' : '');
                $notes = $this->showsNotes() ? implode('; ', array_map('strval', (array) ($line['notes'] ?? []))) : '';
                $writer->addRow(new Row([
                    self::text($project),
                    self::text((string) ($line['item_key'] ?? '')),
                    self::text((string) (($line['item_title'] ?? null) ?: (($line['item_key'] ?? null) ? '' : 'No ticket'))),
                    self::text((string) data_get($line, 'activity.name', '')),
                    self::text($this->showsPeople() ? (string) ($line['person'] ?? '') : ''),
                    new NumericCell(((int) ($line['minutes'] ?? 0)) / 60, $hours),
                    new NumericCell(((int) ($line['billed_minutes'] ?? 0)) / 60, $hours),
                    new NumericCell(((int) ($line['rate_cents'] ?? 0)) / 100, $money),
                    new NumericCell(((int) ($line['amount_cents'] ?? 0)) / 100, $money),
                    self::text(trim($note.($note !== '' && $notes !== '' ? ' · ' : '').$notes)),
                ]));
            }
            $writer->addRow(new Row([
                self::text('Subtotal '.$project, $bold), self::blank(), self::blank(), self::blank(), self::blank(),
                new NumericCell(((int) ($group['minutes'] ?? 0)) / 60, $boldHours),
                new NumericCell(((int) ($group['billed_minutes'] ?? 0)) / 60, $boldHours),
                self::blank(),
                new NumericCell(((int) ($group['net_cents'] ?? 0)) / 100, $boldMoney),
            ]));
        }

        $totals = (array) ($d['totals'] ?? []);
        $tax = self::percent($d['tax_rate_bp'] ?? 0);
        $gross = (bool) ($d['prices_include_tax'] ?? false);
        $totalRow = fn (string $label, int $cents, Style $style) => new Row([
            self::text($label, $bold), self::blank(), self::blank(), self::blank(), self::blank(),
            self::blank(), self::blank(), self::blank(), new NumericCell($cents / 100, $style),
        ]);

        $writer->addRow(new Row([]));
        $writer->addRow(new Row([
            self::text('Total hours', $bold), self::blank(), self::blank(), self::blank(), self::blank(),
            new NumericCell(((int) ($totals['minutes'] ?? 0)) / 60, $boldHours),
            new NumericCell(((int) ($totals['billed_minutes'] ?? 0)) / 60, $boldHours),
        ]));
        $writer->addRow($totalRow('Net', (int) ($totals['net_cents'] ?? 0), $money));
        $writer->addRow($totalRow(($gross ? 'Included VAT ' : 'VAT ').$tax.' %', (int) ($totals['tax_cents'] ?? 0), $money));
        $writer->addRow($totalRow($gross ? 'Total (incl. VAT)' : 'Total', (int) ($totals['gross_cents'] ?? 0), $boldMoney));
    }

    private function writeEntries(Writer $writer): void
    {
        $bold = (new Style)->withFontBold(true);
        $date = (new Style)->withFormat('yyyy-mm-dd');
        $hours = (new Style)->withFormat('0.00');

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Entries');
        $sheet->setColumnWidth(12, 1);
        $sheet->setColumnWidth(18, 2);
        $sheet->setColumnWidth(10, 3, 4);
        $sheet->setColumnWidth(36, 5);
        $sheet->setColumnWidth(18, 6);
        $sheet->setColumnWidth(12, 7, 8, 9, 10);
        $sheet->setColumnWidth(40, 11);

        // names and notes only when the report shows them (the client gets this file too)
        $people = $this->showsPeople();
        $notes = $this->showsNotes();
        $header = array_values(array_filter([
            'Date', $people ? 'Person' : null, 'Project', 'Ticket', 'Title', 'Activity', 'Minutes', 'Billed minutes', 'Billed hours', 'Billable', $notes ? 'Note' : null,
        ]));
        $writer->addRow(new Row(array_map(fn ($h) => self::text($h, $bold), $header)));

        foreach ((array) ($this->data['entries'] ?? []) as $entry) {
            $day = $this->date($entry['date'] ?? null);
            $cells = [
                $day ? new DateTimeCell(new DateTimeImmutable($day->format('Y-m-d')), $date) : self::blank(),
                $people ? self::text((string) ($entry['person'] ?? '')) : null,
                self::text((string) ($entry['project_key'] ?? '')),
                self::text((string) ($entry['item_key'] ?? '')),
                self::text((string) ($entry['item_title'] ?? '')),
                self::text((string) ($entry['activity'] ?? '')),
                new NumericCell((int) ($entry['minutes'] ?? 0)),
                new NumericCell((int) ($entry['billed_minutes'] ?? 0)),
                new NumericCell(((int) ($entry['billed_minutes'] ?? 0)) / 60, $hours),
                self::text(($entry['billable'] ?? true) ? 'Yes' : 'No'),
                $notes ? self::text((string) ($entry['note'] ?? '')) : null,
            ];
            $writer->addRow(new Row(array_values(array_filter($cells))));
        }
    }

    /**
     * Always a string cell: openspout's Cell::fromValue() turns any string starting with "=" into a
     * formula, and ticket titles / notes are user text (formula injection).
     */
    private static function text(string $value, ?Style $style = null): Cell
    {
        return $value === '' ? new EmptyCell(null, $style) : new StringCell($value, $style);
    }

    private static function blank(): Cell
    {
        return new EmptyCell(null);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10)) ?: null;
    }
}
