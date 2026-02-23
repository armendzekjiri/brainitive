<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstanceSettings extends Model
{
    protected $fillable = [
        'name',
        'logo',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * Get the singleton instance settings.
     */
    public static function instance(): self
    {
        return static::firstOrCreate([], [
            'name' => 'Brainitive',
        ]);
    }
}
