<?php

namespace App\Http\Requests\AppApi;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A change to a helpdesk ticket (BRAI-420). Authorized before validation: a ticket of a project the user can't
 * open is a 404; one they may only read (project viewers) a 403 (TicketPolicy::update, as the web).
 */
abstract class TicketRequest extends FormRequest
{
    private ?Ticket $model = null;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->ticket());
    }

    public function ticket(): Ticket
    {
        if ($this->model !== null) {
            return $this->model;
        }
        $id = (string) $this->route('ticket');
        abort_unless(preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1, 404);
        $ticket = Ticket::with('helpdesk.project.workspace')->find((int) $id);
        abort_unless($ticket instanceof Ticket && $ticket->helpdesk?->project?->workspace !== null && $this->user()->can('view', $ticket), 404);

        return $this->model = $ticket;
    }

    /** The ticket while a request is being served; null while the OpenAPI generator reads the rules. */
    protected function ticketForRules(): ?Ticket
    {
        return $this->route('ticket') !== null ? $this->ticket() : null;
    }
}
