<?php

namespace Tests\Feature\Helpdesk;

use App\Models\Helpdesk;
use App\Models\HelpdeskMailbox;
use App\Models\User;
use App\Support\Helpdesks\Inbound\InboundMail;
use App\Support\Helpdesks\Inbound\MimeParser;
use Illuminate\Mail\Transport\ArrayTransport;
use Symfony\Component\Mime\Email;
use Tests\Feature\Boards\InteractsWithBoards;

/**
 * A helpdesk with a connected mailbox (support@helpdesk.example, replies through the app mailer)
 * and the .eml fixtures in tests/Fixtures/mail (BRAI-79).
 */
trait InteractsWithHelpdeskMail
{
    use InteractsWithBoards;

    protected Helpdesk $helpdesk;

    protected User $admin;

    protected HelpdeskMailbox $mailbox;

    protected function setUpHelpdeskMail(): void
    {
        $this->setUpBoard();
        $this->admin = User::find($this->workspace->owner_id);
        $this->project->users()->attach($this->admin->id, ['role' => 'admin']);
        $this->helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->admin->id]);
        $this->mailbox = $this->helpdesk->mailbox()->create([
            'enabled' => true,
            'email_address' => 'support@helpdesk.example',
            'imap_host' => 'imap.helpdesk.example',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'support@helpdesk.example',
            'imap_password' => 'imap-secret',
            'imap_folder' => 'INBOX',
            'smtp_mode' => 'app',
        ]);
        config(['mail.from.address' => 'app@brainitive.test']);
    }

    /** A fixture with placeholders replaced. */
    protected function eml(string $name, array $replace = []): string
    {
        return strtr((string) file_get_contents(base_path("tests/Fixtures/mail/{$name}.eml")), $replace);
    }

    protected function receive(string $raw, ?Helpdesk $helpdesk = null): string
    {
        return InboundMail::receive($helpdesk ?? $this->helpdesk, MimeParser::parse($raw));
    }

    /** @return list<Email> mail sent through the app mailer (MAIL_MAILER=array) */
    protected function sentMail(): array
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport->messages()->map(fn ($sent) => $sent->getOriginalMessage())->values()->all();
    }

    protected function clearSentMail(): void
    {
        app('mail.manager')->mailer()->getSymfonyTransport()->flush();
    }
}
