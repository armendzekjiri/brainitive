<?php

namespace App\Http\Requests\AppApi;

use App\Models\FeedbackPost;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A moderation change to a feedback post (BRAI-422). Authorized before validation: a post of a project the user
 * can't open is a 404; project members who aren't project / workspace admins get 403 (the web's feedback page
 * is theirs: ProjectPolicy::update).
 */
abstract class FeedbackPostRequest extends FormRequest
{
    private ?FeedbackPost $model = null;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->feedbackPost()->portal->project);
    }

    public function feedbackPost(): FeedbackPost
    {
        if ($this->model !== null) {
            return $this->model;
        }
        $id = (string) $this->route('post');
        abort_unless(preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1, 404);
        $post = FeedbackPost::with('portal.project.workspace')->find((int) $id);
        abort_unless($post instanceof FeedbackPost && $post->portal?->project?->workspace !== null && $this->user()->can('view', $post->portal->project), 404);

        return $this->model = $post;
    }
}
