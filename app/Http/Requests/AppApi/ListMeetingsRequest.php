<?php

namespace App\Http\Requests\AppApi;

/** `GET /api/v1/meetings?subject_type=&subject_id=`: the meetings of an issue / doc the user can open. */
class ListMeetingsRequest extends MeetingSubjectRequest
{
    protected function ability(): string
    {
        return 'view';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->subjectRules();
    }
}
