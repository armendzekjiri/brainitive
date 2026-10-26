<?php

namespace Tests\Feature\Billing;

use App\Contracts\Billing\BuildsReportData;
use App\Mail\BillingReportMail;
use App\Models\BillingReport;
use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Providers\BillingServiceProvider;
use App\Support\Billing\BillingDocument;
use App\Support\Billing\LiveReportData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;
use ZipArchive;

/**
 * Billing documents (v1.38.0, BRAI-101): PDF and Excel rendered from the report data (snapshot v1,
 * fixture tests/Fixtures/billing-snapshot-v1.php) without recomputing money, and mailing the PDF
 * to the client. Workspace admins only.
 */
class BillingDocumentTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $admin;

    /** @var array<string, mixed> */
    private array $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->admin = $this->workspace->owner;
        $this->snapshot = require base_path('tests/Fixtures/billing-snapshot-v1.php');
    }

    private function report(string $status = 'finalized', array $attributes = [], ?Workspace $workspace = null): BillingReport
    {
        $workspace ??= $this->workspace;
        $client = Client::create(['workspace_id' => $workspace->id, 'name' => 'Muster GmbH', 'email' => 'live@muster.example']);

        return BillingReport::create($attributes + [
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'kind' => $this->snapshot['kind'],
            'number' => $status === 'draft' ? null : $this->snapshot['number'],
            'title' => $this->snapshot['title'],
            'period_from' => '2026-10-01',
            'period_to' => '2026-10-31',
            'status' => $status,
            'snapshot' => $status === 'draft' ? null : $this->snapshot,
            'finalized_at' => $status === 'draft' ? null : now(),
        ]);
    }

    private function url(string $name, BillingReport $report, ?Workspace $workspace = null): string
    {
        return route($name, ['workspace' => ($workspace ?? $this->workspace)->slug, 'billingReport' => $report->id]);
    }

    /** The visible text of a dompdf PDF: inflate the content streams, decode the UTF-16BE strings. */
    private function pdfText(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $text = [];
        foreach ($streams[1] as $stream) {
            $content = @gzuncompress($stream);
            if ($content === false || ! str_contains($content, 'BT')) {
                continue;
            }
            preg_match_all('/\[\((.*?)\)\]\s*TJ|\((.*?)\)\s*Tj/s', $content, $strings, PREG_SET_ORDER);
            foreach ($strings as $match) {
                $raw = preg_replace_callback('/\\\\([0-7]{1,3}|.)/s', fn ($m) => match (true) {
                    ctype_digit($m[1]) => chr(octdec($m[1])),
                    $m[1] === 'n' => "\n",
                    $m[1] === 'r' => "\r",
                    $m[1] === 't' => "\t",
                    default => $m[1],
                }, $match[1] !== '' ? $match[1] : ($match[2] ?? ''));
                $text[] = mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
            }
        }

        return implode("\n", $text);
    }

    /** @return array<string, list<list<mixed>>> sheet name → rows */
    private function readXlsx(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        file_put_contents($path, $bytes);
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();
        @unlink($path);

        return $sheets;
    }

    public function test_the_pdf_renders_the_snapshot_with_escaped_text(): void
    {
        $report = $this->report();

        $response = $this->actingAs($this->admin)->get($this->url('billing.reports.pdf', $report))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename=invoice-inv-2026-014-muster-gmbh.pdf');

        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $text = $this->pdfText($pdf);

        $this->assertStringContainsString('Muster GmbH', $text);
        $this->assertStringContainsString('Invoice INV-2026-014', $text);
        // totals exactly as the snapshot says (net, VAT 19 %, gross), locale-neutral
        $this->assertStringContainsString('€1,387.50', $text);
        $this->assertStringContainsString('VAT 19 %', $text);
        $this->assertStringContainsString('€263.63', $text);
        $this->assertStringContainsString('€1,651.13', $text);
        $this->assertStringContainsString('Fixed price, already billed', $text);
        $this->assertStringContainsString('2026-11-15', $text, 'due date = issued + 14 days');
        $this->assertStringContainsString('DE89 3704 0044 0532 0130 00', $text);
        $this->assertStringContainsString('Page 1 of', $text);
        // the HTML in the ticket title is printed as text, not parsed as markup
        $this->assertStringContainsString('<script>alert(1)</script> & "cart"', $text);
    }

    public function test_the_document_view_escapes_html_and_never_loads_remote_resources(): void
    {
        $html = view('billing.document', (new BillingDocument($this->snapshot, $this->workspace->id))->viewData())->render();

        $this->assertStringContainsString('Checkout &lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;cart&quot;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertDoesNotMatchRegularExpression('/(src|href)\s*=\s*["\']?(https?:|\/\/|file:)/i', $html);
    }

    public function test_the_gross_price_variant_and_a_plain_time_report(): void
    {
        $this->snapshot['kind'] = 'report';
        $this->snapshot['number'] = null;
        $this->snapshot['prices_include_tax'] = true;
        $this->snapshot['options']['show_people'] = false;
        $document = new BillingDocument($this->snapshot, $this->workspace->id);

        $this->assertSame('time-report-2026-10-muster-gmbh.pdf', $document->filename('pdf'));
        $text = $this->pdfText($document->pdf());
        $this->assertStringContainsString('Time report October 2026', $text);
        $this->assertStringContainsString('Included VAT 19 %', $text);
        $this->assertStringContainsString('Total (incl. VAT)', $text);
        $this->assertStringNotContainsString('Max Member', $text);
        $this->assertStringNotContainsString('Invoice', $text);
    }

    public function test_the_logo_is_embedded_from_the_private_disk_only_for_its_own_workspace(): void
    {
        Storage::fake('local');
        $png = imagecreatetruecolor(4, 2);
        ob_start();
        imagepng($png);
        $bytes = (string) ob_get_clean();
        $own = 'billing-logos/'.$this->workspace->id.'/0b8a3f8e-5d0e-4a39-9c1c-1f0e7e5a2b11.png';
        $foreign = 'billing-logos/999999/0b8a3f8e-5d0e-4a39-9c1c-1f0e7e5a2b11.png';
        Storage::disk('local')->put($own, $bytes);
        Storage::disk('local')->put($foreign, $bytes);

        $this->snapshot['issuer']['logo_path'] = $own;
        $this->assertSame('data:image/png;base64,'.base64_encode($bytes), (new BillingDocument($this->snapshot, $this->workspace->id))->viewData()['logo']);
        $this->assertStringStartsWith('%PDF-', (new BillingDocument($this->snapshot, $this->workspace->id))->pdf());

        foreach ([$foreign, '../../.env', 'billing-logos/'.$this->workspace->id.'/../../x.png', 'https://evil.example/logo.png'] as $path) {
            $this->snapshot['issuer']['logo_path'] = $path;
            $this->assertNull((new BillingDocument($this->snapshot, $this->workspace->id))->viewData()['logo'], $path);
        }
    }

    public function test_the_xlsx_has_a_summary_and_an_entries_sheet(): void
    {
        $report = $this->report();

        $response = $this->actingAs($this->admin)->get($this->url('billing.reports.xlsx', $report))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Content-Disposition', 'attachment; filename=invoice-inv-2026-014-muster-gmbh.xlsx');

        $sheets = $this->readXlsx($response->getContent());
        $this->assertSame(['Summary', 'Entries'], array_keys($sheets));

        $summary = $sheets['Summary'];
        $this->assertSame('Time report October 2026', $summary[0][0]);
        $lines = array_values(array_filter($summary, fn ($row) => in_array($row[1] ?? null, ['WEB-12', 'WEB-14', 'WEB-15', 'WEB-3'], true)));
        $this->assertCount(4, $lines);
        $this->assertSame(['WEB Website', 'WEB-12', 'Checkout <script>alert(1)</script> & "cart"', 'Programming', 'Erin Owner'], array_slice($lines[0], 0, 5));
        $this->assertEqualsWithDelta(6.5, $lines[0][6], 0.0001, 'billed hours');
        $this->assertEqualsWithDelta(120.0, $lines[0][7], 0.0001, 'rate');
        $this->assertEqualsWithDelta(780.0, $lines[0][8], 0.0001, 'amount');
        $this->assertSame('=HYPERLINK("http://evil.example","Pricing page")', $lines[1][2], 'kept as text');
        $this->assertSame('Fixed price, already billed', $lines[3][9]);
        $this->assertEqualsWithDelta(0.0, $lines[3][8], 0.0001);

        $totals = collect($summary)->keyBy(fn ($row) => $row[0] ?? '');
        $this->assertEqualsWithDelta(1387.50, $totals['Net'][8], 0.0001);
        $this->assertEqualsWithDelta(263.63, $totals['VAT 19 %'][8], 0.0001);
        $this->assertEqualsWithDelta(1651.13, $totals['Total'][8], 0.0001);

        $entries = $sheets['Entries'];
        $this->assertSame(['Date', 'Person', 'Project', 'Ticket', 'Title', 'Activity', 'Minutes', 'Billed minutes', 'Billed hours', 'Billable', 'Note'], $entries[0]);
        $this->assertCount(1 + count($this->snapshot['entries']), $entries);
        $this->assertSame('2026-10-03', $entries[1][0]->format('Y-m-d'));
        $this->assertSame(['Erin Owner', 'WEB', 'WEB-12'], array_slice($entries[1], 1, 3));
        $this->assertSame([200, 210], array_slice($entries[1], 6, 2));
        $this->assertSame('No', $entries[7][9], 'non-billable entries stay in the detail sheet');

        // no cell is a formula (user text starting with "=" is never evaluated)
        $zip = new ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'xlsx-zip-');
        file_put_contents($path, $response->getContent());
        $zip->open($path);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'xl/worksheets/sheet')) {
                $this->assertStringNotContainsString('<f>', (string) $zip->getFromIndex($i), $name);
            }
        }
        $zip->close();
        @unlink($path);
    }

    public function test_hidden_names_and_notes_stay_out_of_the_excel_and_void_documents_ask_for_no_payment(): void
    {
        $this->snapshot['options']['show_people'] = false;
        $this->snapshot['options']['show_notes'] = false;
        $document = new BillingDocument($this->snapshot, $this->workspace->id, 'void');

        $sheets = $this->readXlsx($document->xlsx());
        $this->assertSame(['Date', 'Project', 'Ticket', 'Title', 'Activity', 'Minutes', 'Billed minutes', 'Billed hours', 'Billable'], $sheets['Entries'][0]);
        $this->assertSame(['WEB', 'WEB-12'], array_slice($sheets['Entries'][1], 1, 2));
        $flat = json_encode($sheets);
        $this->assertStringNotContainsString('Erin Owner', $flat);
        $this->assertStringNotContainsString('Internal review', $flat);
        $this->assertStringNotContainsString('Fixed rounding in cart', $flat);
        $this->assertStringContainsString('VOID', $flat);

        $text = $this->pdfText($document->pdf());
        $this->assertStringContainsString('VOID', $text);
        $this->assertStringNotContainsString('Please pay', $text);
        $this->assertStringNotContainsString('Max Member', $text);
    }

    public function test_a_draft_without_a_report_builder_is_not_available(): void
    {
        $this->app->offsetUnset(BuildsReportData::class);
        $report = $this->report('draft');

        $this->actingAs($this->admin)->get($this->url('billing.reports.pdf', $report))->assertStatus(409);
        $this->actingAs($this->admin)->get($this->url('billing.reports.xlsx', $report))->assertStatus(409);
    }

    public function test_a_draft_renders_live_from_the_bound_report_builder(): void
    {
        $report = $this->report('draft');
        $draft = $this->snapshot;
        $draft['number'] = null;
        $this->app->instance(BuildsReportData::class, new class($draft) implements BuildsReportData
        {
            public function __construct(private array $data) {}

            public function build(BillingReport $report): array
            {
                return $this->data;
            }
        });

        $response = $this->actingAs($this->admin)->get($this->url('billing.reports.pdf', $report))->assertOk();
        $text = $this->pdfText($response->getContent());
        $this->assertStringContainsString('DRAFT', $text);
        $this->assertStringContainsString('€1,651.13', $text);
    }

    public function test_the_report_builder_is_bound_once_it_exists(): void
    {
        // documents ticket alone: no ReportBuilder → unbound; once the reports ticket is merged → bound
        $this->assertSame(LiveReportData::available(), $this->app->bound(BuildsReportData::class));
    }

    public function test_send_queues_the_pdf_to_the_client_and_marks_the_report_sent(): void
    {
        Mail::fake();
        $report = $this->report();

        $this->actingAs($this->admin)->from('/back')
            ->post($this->url('billing.reports.send', $report), [
                'to' => 'accounts@muster.example',
                'cc' => 'boss@muster.example, ACCOUNTS@muster.example; team@acme.example Boss@Muster.example',
                'subject' => 'Invoice INV-2026-014',
                'message' => "Hello <b>Muster</b>,\nplease find the invoice attached.",
            ])
            ->assertRedirect('/back')
            ->assertSessionHasNoErrors();

        Mail::assertQueued(BillingReportMail::class, function (BillingReportMail $mail) use ($report) {
            $this->assertTrue($mail->report->is($report));
            $this->assertTrue($mail->hasTo('accounts@muster.example'));
            $this->assertTrue($mail->hasCc('boss@muster.example'));
            $this->assertTrue($mail->hasCc('team@acme.example'));
            $this->assertCount(2, $mail->cc, 'the "to" address is not repeated in copy');
            $this->assertSame('Invoice INV-2026-014', $mail->envelope()->subject);
            $this->assertSame($this->admin->email, $mail->envelope()->replyTo[0]->address);

            $attachments = $mail->attachments();
            $this->assertCount(1, $attachments);
            $this->assertSame('invoice-inv-2026-014-muster-gmbh.pdf', $attachments[0]->as);
            $this->assertSame('application/pdf', $attachments[0]->mime);
            $pdf = $attachments[0]->attachWith(fn () => null, fn ($data) => $data());
            $this->assertStringStartsWith('%PDF-', $pdf);

            // it survives the queue: only the report's id is serialized, the PDF is rendered in the worker
            $queued = unserialize(serialize($mail));
            $this->assertTrue($queued->report->is($report));
            $this->assertStringStartsWith('%PDF-', $queued->attachments()[0]->attachWith(fn () => null, fn ($data) => $data()));
            $this->assertStringContainsString(' via ', $queued->envelope()->from->name);

            $html = $mail->render();
            $this->assertStringContainsString('Hello &lt;b&gt;Muster&lt;/b&gt;,<br>', $html); // escaped, line breaks kept (branded layout, BRAI-155)
            $this->assertStringContainsString('invoice-inv-2026-014-muster-gmbh.pdf', $html);

            return true;
        });

        $report->refresh();
        $this->assertSame('sent', $report->status);
        $this->assertNotNull($report->sent_at);
        $this->assertSame('accounts@muster.example', $report->sent_to);
    }

    public function test_send_defaults_to_the_clients_email_and_keeps_paid_reports_paid(): void
    {
        Mail::fake();
        $report = $this->report('paid');

        $this->actingAs($this->admin)
            ->post($this->url('billing.reports.send', $report), ['subject' => 'Invoice again'])
            ->assertSessionHasNoErrors();

        Mail::assertQueued(BillingReportMail::class, fn ($mail) => $mail->hasTo('accounts@muster.example'));
        $this->assertSame('paid', $report->fresh()->status);
        $this->assertSame('accounts@muster.example', $report->fresh()->sent_to);
    }

    public function test_send_is_refused_for_drafts_and_void_reports(): void
    {
        Mail::fake();

        foreach (['draft', 'void'] as $status) {
            $report = $this->report($status, ['snapshot' => $this->snapshot]);
            $this->actingAs($this->admin)
                ->postJson($this->url('billing.reports.send', $report), ['to' => 'a@muster.example', 'subject' => 'Report'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('status');
            $this->assertNull($report->fresh()->sent_at);
            $this->assertSame($status, $report->fresh()->status);
        }

        Mail::assertNothingQueued();
    }

    public function test_send_validates_addresses_and_refuses_header_injection(): void
    {
        Mail::fake();
        $report = $this->report();
        $cases = [
            'to' => ['to' => "a@muster.example\r\nBcc: spy@evil.example", 'subject' => 'Report'],
            'subject' => ['to' => 'a@muster.example', 'subject' => "Report\r\nBcc: spy@evil.example"],
            'cc' => ['to' => 'a@muster.example', 'cc' => 'not-an-email', 'subject' => 'Report'],
        ];
        foreach ($cases as $field => $payload) {
            $this->actingAs($this->admin)->postJson($this->url('billing.reports.send', $report), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $sixCc = implode(',', array_map(fn ($i) => "cc{$i}@muster.example", range(1, 6)));
        $this->actingAs($this->admin)->postJson($this->url('billing.reports.send', $report), ['to' => 'a@muster.example', 'cc' => $sixCc, 'subject' => 'Report'])
            ->assertJsonValidationErrors('cc');
        $this->actingAs($this->admin)->postJson($this->url('billing.reports.send', $report), ['to' => 'a@muster.example'])
            ->assertJsonValidationErrors('subject');

        Mail::assertNothingQueued();
        $this->assertSame('finalized', $report->fresh()->status);
    }

    public function test_sending_is_limited_per_admin_and_per_workspace_per_day(): void
    {
        $route = app('router')->getRoutes()->getByName('billing.reports.send');
        $this->assertContains('throttle:billing-send', $route->gatherMiddleware());

        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->admin);
        $request->setRouteResolver(fn () => tap(new Route('POST', 'x/{workspace}', []), fn ($r) => $r->bind($request)->setParameter('workspace', $this->workspace)));
        $limits = RateLimiter::limiter('billing-send')($request);

        $this->assertSame([10, BillingServiceProvider::SENDS_PER_WORKSPACE_PER_DAY], array_map(fn ($limit) => $limit->maxAttempts, $limits));
        $this->assertSame([60, 86400], array_map(fn ($limit) => $limit->decaySeconds, $limits));
        $this->assertStringEndsWith(':'.$this->workspace->id, $limits[1]->key);
    }

    public function test_members_and_outsiders_get_403_everywhere(): void
    {
        Mail::fake();
        $report = $this->report();

        foreach ([$this->editor, $this->viewer, $this->outsider] as $user) {
            $this->actingAs($user)->get($this->url('billing.reports.pdf', $report))->assertForbidden();
            $this->actingAs($user)->get($this->url('billing.reports.xlsx', $report))->assertForbidden();
            $this->actingAs($user)->post($this->url('billing.reports.send', $report), ['to' => 'a@muster.example', 'subject' => 'x'])->assertForbidden();
        }

        Mail::assertNothingQueued();
    }

    public function test_another_workspaces_report_is_not_found(): void
    {
        Mail::fake();
        $other = Workspace::factory()->create(['owner_id' => $this->admin->id]);
        $other->addUser($this->admin, 'admin');
        $foreign = $this->report('finalized', [], $other);

        $this->actingAs($this->admin)->get($this->url('billing.reports.pdf', $foreign))->assertNotFound();
        $this->actingAs($this->admin)->get($this->url('billing.reports.xlsx', $foreign))->assertNotFound();
        $this->actingAs($this->admin)->post($this->url('billing.reports.send', $foreign), ['to' => 'a@muster.example', 'subject' => 'x'])->assertNotFound();

        Mail::assertNothingQueued();
        $this->assertNull($foreign->fresh()->sent_at);
    }
}
