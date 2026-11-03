<?php

namespace App\Http\Requests\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Models\Item;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A request about the meetings of one issue or doc (BRAI-418): `subject_type` item | page and `subject_id`
 * (an issue id / a doc uuid). Authorized before validation: an issue / doc the user can't open is a 404.
 */
abstract class MeetingSubjectRequest extends FormRequest
{
    use ResolvesVisible;

    private Item|Page|null $subjectModel = null;

    public function authorize(): bool
    {
        $subject = $this->subject();

        return $subject === null || $this->user()->can($this->ability(), $subject);
    }

    /** view (list) or update (schedule) of the issue / doc. */
    abstract protected function ability(): string;

    /** @return array<string, mixed> */
    protected function subjectRules(): array
    {
        return [
            // item (an issue) | page (a doc)
            'subject_type' => ['required', 'string', Rule::in(array_keys(Meeting::SUBJECTS))],
            // the issue's id, or the doc's uuid
            'subject_id' => ['required', 'string', 'max:64'],
        ];
    }

    /** The project of the issue / doc (where it is now). */
    public function subjectProject(): ?Project
    {
        $subject = $this->subject();

        return $subject instanceof Item ? $subject->board?->project : $subject?->project;
    }

    /** The issue / doc (404 when the user can't open it); null while the input can't name one (→ 422). */
    public function subject(): Item|Page|null
    {
        if ($this->subjectModel !== null) {
            return $this->subjectModel;
        }
        $type = $this->input('subject_type');
        $id = $this->input('subject_id');
        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        return $this->subjectModel = match ($type) {
            'item' => $this->visibleItem($this, (string) $id),
            'page' => $this->visiblePage($this, (string) $id),
            default => null,
        };
    }
}
