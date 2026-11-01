<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/** `GET /api/v1/projects/{id}/items`: the list rules + a date range, a board and done / open. */
class ListDatedIssuesRequest extends ListRequest
{
    /** The longest range one call may ask for. */
    public const MAX_DAYS = 366;

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // first day, Y-m-d (default: the user's today)
            'from' => ['sometimes', 'date_format:Y-m-d'],
            // last day, inclusive (default: from + 30 days); at most 366 days after from
            'to' => ['sometimes', 'date_format:Y-m-d'],
            // only issues of this board of the project (its id)
            'board' => ['sometimes', 'integer', 'min:1'],
            // open | done | all (default)
            'status' => ['sometimes', 'in:open,done,all'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $days = Carbon::parse($this->from())->diffInDays(Carbon::parse($this->to()), false);
            if ($days < 0) {
                $validator->errors()->add('to', 'The last day must not be before the first day.');
            } elseif ($days > self::MAX_DAYS) {
                $validator->errors()->add('to', 'Ask for at most '.self::MAX_DAYS.' days at a time.');
            }
        }];
    }

    public function from(): string
    {
        $from = $this->input('from');

        return is_string($from) && $from !== '' ? $from : now($this->user()->localTimezone())->toDateString();
    }

    public function to(): string
    {
        $to = $this->input('to');

        return is_string($to) && $to !== '' ? $to : Carbon::parse($this->from())->addDays(30)->toDateString();
    }

    public function board(): ?int
    {
        $board = $this->validated('board');

        return $board === null ? null : (int) $board;
    }

    public function status(): string
    {
        return (string) ($this->validated('status') ?? 'all');
    }
}
