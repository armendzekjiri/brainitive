<?php

/**
 * A complete "BillingReport snapshot v1" (docs/ai/tasks/release-v1.38.0-billing.md), consistent with
 * the money rules: net prices, 19 % VAT, 15-minute rounding per entry. Shared by the billing tests —
 * `$snapshot = require base_path('tests/Fixtures/billing-snapshot-v1.php');` then override keys.
 *
 * Lines: hourly 6.5 h × 120.00 = 780.00, hourly 2 h × 45.00 = 90.00, fixed 450.00, fixed already
 * billed 0.00, no ticket 0.75 h × 90.00 = 67.50 → net 1,387.50, VAT round(138750 × 0.19) = 263.63,
 * gross 1,651.13. The first title carries HTML to prove documents escape it.
 */
return [
    'version' => 1,
    'kind' => 'invoice',
    'number' => 'INV-2026-014',
    'title' => 'Time report October 2026',
    'issued_on' => '2026-11-01',
    'period' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
    'currency' => 'EUR',
    'tax_rate_bp' => 1900,
    'prices_include_tax' => false,
    'rounding_minutes' => 15,
    'issuer' => [
        'company_name' => 'Acme Studio GmbH',
        'address' => "Hauptstraße 1\n10115 Berlin\nGermany",
        'vat_id' => 'DE123456789',
        'email' => 'billing@acme.example',
        'phone' => '+49 30 1234567',
        'bank_name' => 'Berliner Bank',
        'iban' => 'DE89370400440532013000',
        'bic' => 'COBADEFFXXX',
        'footer' => 'Acme Studio GmbH · Managing director: Erin Owner · Registered at Amtsgericht Berlin HRB 12345',
        'logo_path' => null,
        'payment_terms_days' => 14,
    ],
    'client' => [
        'name' => 'Muster GmbH',
        'email' => 'accounts@muster.example',
        'address' => "Musterweg 5\n80331 München",
        'vat_id' => 'DE987654321',
    ],
    'options' => ['group_by' => 'ticket', 'show_people' => true, 'show_notes' => true],
    'groups' => [
        [
            'project' => ['id' => 1, 'name' => 'Website', 'key' => 'WEB'],
            'lines' => [
                [
                    'item_key' => 'WEB-12', 'item_title' => 'Checkout <script>alert(1)</script> & "cart"',
                    'activity' => ['name' => 'Programming', 'pricing_type' => 'hourly'],
                    'person' => 'Erin Owner',
                    'minutes' => 385, 'billed_minutes' => 390,
                    'rate_cents' => 12000, 'amount_cents' => 78000,
                    'fixed_already_billed' => false,
                    'notes' => ['Fixed rounding in cart'],
                    'entry_ids' => [11, 12],
                ],
                [
                    'item_key' => 'WEB-14', 'item_title' => '=HYPERLINK("http://evil.example","Pricing page")',
                    'activity' => ['name' => 'Testing', 'pricing_type' => 'hourly'],
                    'person' => 'Max Member',
                    'minutes' => 118, 'billed_minutes' => 120,
                    'rate_cents' => 4500, 'amount_cents' => 9000,
                    'fixed_already_billed' => false,
                    'notes' => [],
                    'entry_ids' => [13],
                ],
                [
                    'item_key' => 'WEB-15', 'item_title' => 'SSM setup',
                    'activity' => ['name' => 'SSM setup', 'pricing_type' => 'fixed'],
                    'person' => 'Erin Owner',
                    'minutes' => 60, 'billed_minutes' => 60,
                    'rate_cents' => 45000, 'amount_cents' => 45000,
                    'fixed_already_billed' => false,
                    'notes' => [],
                    'entry_ids' => [14],
                ],
                [
                    'item_key' => 'WEB-3', 'item_title' => 'Analytics setup',
                    'activity' => ['name' => 'SSM setup', 'pricing_type' => 'fixed'],
                    'person' => 'Erin Owner',
                    'minutes' => 30, 'billed_minutes' => 30,
                    'rate_cents' => 45000, 'amount_cents' => 0,
                    'fixed_already_billed' => true,
                    'notes' => [],
                    'entry_ids' => [15],
                ],
            ],
            'minutes' => 593, 'billed_minutes' => 600, 'net_cents' => 132000,
        ],
        [
            'project' => ['id' => 2, 'name' => 'Mobile app', 'key' => 'APP'],
            'lines' => [
                [
                    'item_key' => null, 'item_title' => null,
                    'activity' => ['name' => 'Consulting', 'pricing_type' => 'hourly'],
                    'person' => 'Erin Owner',
                    'minutes' => 45, 'billed_minutes' => 45,
                    'rate_cents' => 9000, 'amount_cents' => 6750,
                    'fixed_already_billed' => false,
                    'notes' => ['Kick-off call'],
                    'entry_ids' => [16],
                ],
            ],
            'minutes' => 45, 'billed_minutes' => 45, 'net_cents' => 6750,
        ],
    ],
    'totals' => ['minutes' => 638, 'billed_minutes' => 645, 'net_cents' => 138750, 'tax_cents' => 26363, 'gross_cents' => 165113],
    'entries' => [
        ['id' => 11, 'date' => '2026-10-03', 'person' => 'Erin Owner', 'project_key' => 'WEB', 'item_key' => 'WEB-12', 'item_title' => 'Checkout <script>alert(1)</script> & "cart"', 'activity' => 'Programming', 'minutes' => 200, 'billed_minutes' => 210, 'billable' => true, 'note' => 'Fixed rounding in cart'],
        ['id' => 12, 'date' => '2026-10-06', 'person' => 'Erin Owner', 'project_key' => 'WEB', 'item_key' => 'WEB-12', 'item_title' => 'Checkout <script>alert(1)</script> & "cart"', 'activity' => 'Programming', 'minutes' => 185, 'billed_minutes' => 180, 'billable' => true, 'note' => null],
        ['id' => 13, 'date' => '2026-10-08', 'person' => 'Max Member', 'project_key' => 'WEB', 'item_key' => 'WEB-14', 'item_title' => '=HYPERLINK("http://evil.example","Pricing page")', 'activity' => 'Testing', 'minutes' => 118, 'billed_minutes' => 120, 'billable' => true, 'note' => '=1+1'],
        ['id' => 14, 'date' => '2026-10-10', 'person' => 'Erin Owner', 'project_key' => 'WEB', 'item_key' => 'WEB-15', 'item_title' => 'SSM setup', 'activity' => 'SSM setup', 'minutes' => 60, 'billed_minutes' => 60, 'billable' => true, 'note' => null],
        ['id' => 15, 'date' => '2026-10-12', 'person' => 'Erin Owner', 'project_key' => 'WEB', 'item_key' => 'WEB-3', 'item_title' => 'Analytics setup', 'activity' => 'SSM setup', 'minutes' => 30, 'billed_minutes' => 30, 'billable' => true, 'note' => null],
        ['id' => 16, 'date' => '2026-10-20', 'person' => 'Erin Owner', 'project_key' => 'APP', 'item_key' => null, 'item_title' => null, 'activity' => 'Consulting', 'minutes' => 45, 'billed_minutes' => 45, 'billable' => true, 'note' => 'Kick-off call'],
        ['id' => 17, 'date' => '2026-10-21', 'person' => 'Max Member', 'project_key' => 'WEB', 'item_key' => 'WEB-12', 'item_title' => 'Checkout <script>alert(1)</script> & "cart"', 'activity' => 'Programming', 'minutes' => 20, 'billed_minutes' => 30, 'billable' => false, 'note' => 'Internal review'],
    ],
];
