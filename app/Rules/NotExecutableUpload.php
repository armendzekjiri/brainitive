<?php

namespace App\Rules;

use App\Support\Uploads;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Refuses programs and scripts (by any extension in the name, e.g. invoice.pdf.exe, and by the
 * detected content type) — for uploads anyone can make without an account, so a helpdesk can't be
 * used to hand malware to the team. Stored names are safe anyway (Uploads::name); this is about
 * what people download and might open.
 */
class NotExecutableUpload implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof UploadedFile && Uploads::isExecutable($value)) {
            $fail('Programs and scripts can\'t be attached here.');
        }
    }
}
