<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class TicketContact extends Model
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'uuid',
        'email',
        'name',
    ];

    /** Legacy contact-wide tokens (replaced by Ticket::publicAccessToken()); cleared by a migration. */
    protected $hidden = [
        'access_token',
        'access_token_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token_expires_at' => 'datetime',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (TicketContact $contact) {
            if (empty($contact->uuid)) {
                $contact->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Get all tickets for this contact.
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Get all replies from this contact.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }

    /**
     * Find a contact by email or create a new one.
     */
    public static function findOrCreateByEmail(string $email, ?string $name = null): self
    {
        $contact = self::where('email', $email)->first();

        if (! $contact) {
            $contact = self::create([
                'email' => $email,
                'name' => $name,
            ]);
        } elseif ($name && ! $contact->name) {
            $contact->update(['name' => $name]);
        }

        return $contact;
    }
}
