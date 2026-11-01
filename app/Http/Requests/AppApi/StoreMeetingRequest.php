<?php

namespace App\Http\Requests\AppApi;

use App\Support\Meetings\MeetingScheduler;

/**
 * `POST /api/v1/meetings`: schedule an online meeting for an issue / doc (the web's "Schedule meeting"
 * dialog, MeetingScheduler). Needs edit rights on the issue / doc (403) and one of the user's own calendars.
 */
class StoreMeetingRequest extends MeetingSubjectRequest
{
    protected function ability(): string
    {
        return 'update';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $project = $this->route() !== null ? $this->subjectProject() : null;

        return $this->subjectRules() + array_merge(MeetingScheduler::rules($project), [
            // IANA zone of date / start_time, default the user's (effective_timezone of GET /me)
            'timezone' => ['sometimes', 'string', 'timezone:all_with_bc'],
            // retry key: the same id + input answers 200 with the meeting the first call created
            'request_id' => ['nullable', 'uuid'],
        ]);
    }
}
