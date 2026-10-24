<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workspace's billing configuration (v1.38.0): currency, default VAT, net/gross prices, rounding
 * (per entry, reports only), invoice mode + numbering and the issuer details printed on documents.
 * One row per workspace — use forWorkspace(), which returns unsaved defaults when none exists.
 */
class BillingSetting extends Model
{
    public const ROUNDING_OPTIONS = [0, 5, 15, 30];

    protected $fillable = [
        'workspace_id', 'currency', 'default_tax_rate_bp', 'prices_include_tax', 'rounding_minutes', 'invoice_mode',
        'number_prefix', 'next_number', 'payment_terms_days', 'company_name', 'company_address', 'company_vat_id',
        'company_email', 'company_phone', 'bank_name', 'iban', 'bic', 'footer', 'logo_path',
    ];

    protected function casts(): array
    {
        return [
            'default_tax_rate_bp' => 'integer',
            'prices_include_tax' => 'boolean',
            'rounding_minutes' => 'integer',
            'invoice_mode' => 'boolean',
            'next_number' => 'integer',
            'payment_terms_days' => 'integer',
        ];
    }

    public static function forWorkspace(Workspace $workspace): self
    {
        return self::firstOrNew(['workspace_id' => $workspace->id], [
            'currency' => 'EUR',
            'default_tax_rate_bp' => 0,
            'prices_include_tax' => false,
            'rounding_minutes' => 0,
            'invoice_mode' => false,
            'next_number' => 1,
        ]);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
