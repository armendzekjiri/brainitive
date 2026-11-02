<?php

namespace App\Http\Requests\AppApi;

use App\Models\Item;
use App\Support\Billing\TimeLog;
use Illuminate\Foundation\Http\FormRequest;

/** `POST /api/v1/me/timer/stop`: the reviewed time, as the web's stop dialog. */
class StopTimerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $duration = Item::durationRules();
        $duration[0] = 'sometimes';

        return [
            // the timer the user reviewed (GET /me/timer → timer.id): a stale device never logs onto a newer timer
            'timer_id' => ['required', 'integer'],
            // 45m, 1h 30m, 2h, 1d or plain minutes ("90"); default: the elapsed minutes
            'time' => $duration,
            'note' => ['nullable', 'string', 'max:255'],
            // required while the workspace has activities (GET /me/timer → activity_required)
            'activity_id' => ['nullable'],
            // Y-m-d, default: the user's today
            'spent_on' => TimeLog::spentOnRules($this->user()),
        ];
    }
}
