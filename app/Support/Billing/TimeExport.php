<?php

namespace App\Support\Billing;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel export of the team time overview (BRAI-100): exactly the filtered entries, one row per entry,
 * streamed — rows go from the database cursor straight into openspout's temp sheet file, and the zip is
 * written to the response at the end, so memory stays flat for any month.
 *
 * Every text cell is a StringCell: Cell::fromValue() turns a string starting with "=" into a formula,
 * and notes and ticket titles are user input (spreadsheet formula injection).
 */
final class TimeExport
{
    public const HEADERS = [
        'Date', 'Person', 'Client', 'Project', 'Ticket key', 'Ticket title', 'Activity', 'Pricing type',
        'Hours', 'Minutes', 'Billable', 'Billed (report)', 'Note',
    ];

    public static function download(TimeOverview $overview): StreamedResponse
    {
        return response()->streamDownload(function () use ($overview) {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $bold = (new Style)->withFontBold(true);
            $writer->addRow(new Row(array_map(fn (string $header) => new StringCell($header, $bold), self::HEADERS)));

            foreach ($overview->query()->cursor() as $entry) {
                $writer->addRow(self::row($entry));
            }

            $writer->close();
        }, $overview->filename(), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private static function row(object $entry): Row
    {
        $ticket = TimeOverview::ticket($entry);
        $minutes = (int) $entry->minutes;

        return new Row([
            self::text((string) $entry->spent_on),
            self::text($entry->user_name),
            self::text($entry->client_name),
            self::text($entry->project_name ?? TimeOverview::PROJECT_GONE),
            self::text($ticket['key']),
            self::text($ticket['title']),
            self::text($entry->activity_name),
            self::text($entry->pricing_type),
            new NumericCell(round($minutes / 60, 2)),
            new NumericCell($minutes),
            self::text($entry->billable ? 'Yes' : 'No'),
            self::text(TimeOverview::billedLabel($entry)),
            self::text($entry->note),
        ]);
    }

    private static function text(?string $value): Cell
    {
        return $value === null || $value === '' ? new EmptyCell(null) : new StringCell($value);
    }
}
