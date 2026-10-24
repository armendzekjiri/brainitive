<?php

namespace Tests\Feature\Billing;

use App\Models\BillingReport;
use App\Models\BillingSetting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Billing → Settings (v1.38.0, BRAI-101): workspace admins configure prices, VAT, rounding,
 * invoice numbering, issuer + bank details and a logo kept on the private disk.
 */
class BillingSettingsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->admin = $this->workspace->owner;
        Storage::fake('local');
        Storage::fake('public');
    }

    private function url(string $name = 'billing.settings.edit', ?Workspace $workspace = null): string
    {
        return route($name, ($workspace ?? $this->workspace)->slug);
    }

    /** @return array<string, mixed> */
    private function valid(array $overrides = []): array
    {
        return $overrides + [
            'currency' => 'EUR',
            'default_tax_rate' => '19',
            'prices_include_tax' => false,
            'rounding_minutes' => 15,
            'invoice_mode' => true,
            'number_prefix' => 'INV-2026-',
            'next_number' => 14,
            'payment_terms_days' => 14,
            'company_name' => 'Acme Studio GmbH',
            'company_address' => "Hauptstraße 1\n10115 Berlin",
            'company_vat_id' => 'DE123456789',
            'company_email' => 'billing@acme.example',
            'company_phone' => '+49 30 1234567',
            'bank_name' => 'Berliner Bank',
            'iban' => 'de89 3704 0044 0532 0130 00',
            'bic' => 'cobadeffxxx',
            'footer' => 'Registered at Amtsgericht Berlin',
        ];
    }

    public function test_admin_sees_the_defaults_before_anything_is_saved(): void
    {
        $this->actingAs($this->admin)->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Billing/Settings')
                ->where('settings.currency', 'EUR')
                ->where('settings.default_tax_rate', '0.00')
                ->where('settings.invoice_mode', false)
                ->where('settings.next_number', 1)
                ->where('logoUrl', null)
                ->has('currencies')
                ->where('roundingOptions', [0, 5, 15, 30]));

        $this->assertDatabaseCount('billing_settings', 0);
    }

    public function test_admin_saves_the_settings_normalised(): void
    {
        $this->actingAs($this->admin)->from($this->url())
            ->put($this->url('billing.settings.update'), $this->valid(['default_tax_rate' => '7.75', 'currency' => 'chf']))
            ->assertRedirect($this->url())
            ->assertSessionHasNoErrors();

        $settings = BillingSetting::where('workspace_id', $this->workspace->id)->sole();
        $this->assertSame('CHF', $settings->currency);
        $this->assertSame(775, $settings->default_tax_rate_bp);
        $this->assertSame(15, $settings->rounding_minutes);
        $this->assertTrue($settings->invoice_mode);
        $this->assertSame('INV-2026-', $settings->number_prefix);
        $this->assertSame(14, $settings->next_number);
        $this->assertSame('DE89370400440532013000', $settings->iban);
        $this->assertSame('COBADEFFXXX', $settings->bic);
        $this->assertSame("Hauptstraße 1\n10115 Berlin", $settings->company_address);

        $this->actingAs($this->admin)->get($this->url())
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.default_tax_rate', '7.75')
                ->where('settings.iban', 'DE89370400440532013000'));

        // a second save updates the same row; empty optional fields become null
        $this->actingAs($this->admin)->put($this->url('billing.settings.update'), $this->valid(['iban' => '', 'bic' => '', 'payment_terms_days' => '', 'invoice_mode' => false]))
            ->assertSessionHasNoErrors();
        $settings->refresh();
        $this->assertNull($settings->iban);
        $this->assertNull($settings->payment_terms_days);
        $this->assertFalse($settings->invoice_mode);
        $this->assertDatabaseCount('billing_settings', 1);
    }

    public function test_settings_validation_rejects_bad_values(): void
    {
        $cases = [
            'currency' => ['EURO', '', '€'],
            'default_tax_rate' => ['-1', '100.01', '19.123', 'abc'],
            'rounding_minutes' => [10, 60],
            'next_number' => [0, 'x'],
            'payment_terms_days' => [-1, 400],
            'number_prefix' => ['<b>', str_repeat('A', 21)],
            'company_email' => ['not-an-email'],
            'iban' => ['DE89', '1234567890123456', 'DE89 3704 0044 0532 0130 00 1234 5678 9012 3'],
            'bic' => ['ABC', 'COBA-DEFF'],
        ];

        foreach ($cases as $field => $values) {
            foreach ($values as $value) {
                $this->actingAs($this->admin)
                    ->put($this->url('billing.settings.update'), $this->valid([$field => $value]))
                    ->assertSessionHasErrors($field);
            }
        }

        // arrays are refused, not a 500
        foreach (['currency', 'iban', 'bic'] as $field) {
            $this->actingAs($this->admin)
                ->put($this->url('billing.settings.update'), $this->valid([$field => ['x']]))
                ->assertSessionHasErrors($field);
        }

        $this->assertDatabaseCount('billing_settings', 0);
    }

    public function test_members_and_outsiders_cannot_see_or_change_settings(): void
    {
        foreach ([$this->editor, $this->viewer, $this->outsider] as $user) {
            $this->actingAs($user)->get($this->url())->assertForbidden();
            $this->actingAs($user)->put($this->url('billing.settings.update'), $this->valid())->assertForbidden();
            $this->actingAs($user)->post($this->url('billing.settings.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png', 120, 60)])->assertForbidden();
            $this->actingAs($user)->delete($this->url('billing.settings.logo.destroy'))->assertForbidden();
            $this->actingAs($user)->get($this->url('billing.settings.logo'))->assertForbidden();
        }

        $this->assertDatabaseCount('billing_settings', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_logo_is_reencoded_and_stored_on_the_private_disk(): void
    {
        $this->actingAs($this->admin)
            ->post($this->url('billing.settings.logo.store'), ['logo' => UploadedFile::fake()->image('logo.jpg', 1500, 400)])
            ->assertSessionHasNoErrors();

        $path = BillingSetting::where('workspace_id', $this->workspace->id)->value('logo_path');
        $this->assertMatchesRegularExpression('#^billing-logos/'.$this->workspace->id.'/[0-9a-f-]{36}\.png$#', $path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'never on the public disk');

        // re-encoded as PNG and scaled into 1200 × 600
        $info = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame(IMAGETYPE_PNG, $info[2]);
        $this->assertSame(1200, $info[0]);
        $this->assertSame(320, $info[1]);

        // served to admins only, through the settings route
        $this->actingAs($this->admin)->get($this->url())
            ->assertInertia(fn (Assert $page) => $page->where('logoUrl', fn ($url) => str_contains($url, '/billing/settings/logo')));
        $this->actingAs($this->admin)->get($this->url('billing.settings.logo'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_anything_appended_to_an_image_is_dropped(): void
    {
        $image = UploadedFile::fake()->image('logo.png', 80, 40);
        $polyglot = UploadedFile::fake()->createWithContent('logo.png', file_get_contents($image->getRealPath()).'<?php echo "pwned"; ?><script>alert(1)</script>');

        $this->actingAs($this->admin)->post($this->url('billing.settings.logo.store'), ['logo' => $polyglot])->assertSessionHasNoErrors();

        $stored = Storage::disk('local')->get(BillingSetting::where('workspace_id', $this->workspace->id)->value('logo_path'));
        $this->assertStringNotContainsString('<?php', $stored);
        $this->assertStringNotContainsString('<script', $stored);
    }

    public function test_webp_logos_are_accepted(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD without WebP support');
        }

        $this->actingAs($this->admin)
            ->post($this->url('billing.settings.logo.store'), ['logo' => UploadedFile::fake()->image('logo.webp', 100, 50)])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(BillingSetting::where('workspace_id', $this->workspace->id)->value('logo_path'));
    }

    public function test_logo_upload_refuses_other_types_large_files_and_huge_images(): void
    {
        $files = [
            'svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'svg renamed' => UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'gif' => UploadedFile::fake()->image('logo.gif', 50, 50),
            'pdf' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
            'over 1 MB' => UploadedFile::fake()->image('logo.png', 100, 100)->size(1100),
            'too many pixels' => UploadedFile::fake()->image('logo.png', 2600, 10),
        ];

        foreach ($files as $case => $file) {
            $this->actingAs($this->admin)
                ->post($this->url('billing.settings.logo.store'), ['logo' => $file])
                ->assertSessionHasErrors('logo');
            $this->assertSame([], Storage::disk('local')->allFiles(), $case);
        }
    }

    public function test_replacing_or_removing_the_logo_deletes_the_old_file_unless_a_finalized_document_prints_it(): void
    {
        $upload = fn () => $this->actingAs($this->admin)
            ->post($this->url('billing.settings.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png', 120, 60)])
            ->assertSessionHasNoErrors();
        $current = fn () => BillingSetting::where('workspace_id', $this->workspace->id)->value('logo_path');

        $upload();
        $first = $current();
        $upload();
        $second = $current();
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);

        // a finalized report printed the second logo: it stays when the logo changes again
        $snapshot = require base_path('tests/Fixtures/billing-snapshot-v1.php');
        $snapshot['issuer']['logo_path'] = $second;
        BillingReport::create(['workspace_id' => $this->workspace->id, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'status' => 'finalized', 'snapshot' => $snapshot]);

        $this->actingAs($this->admin)->delete($this->url('billing.settings.logo.destroy'))->assertSessionHasNoErrors();
        $this->assertNull($current());
        Storage::disk('local')->assertExists($second);

        $this->actingAs($this->admin)->get($this->url('billing.settings.logo'))->assertNotFound();
    }
}
