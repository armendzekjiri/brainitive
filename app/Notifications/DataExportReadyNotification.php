<?php

namespace App\Notifications;

use App\Models\DataExport;
use App\Notifications\Messages\BrandedMailMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Number;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * "Your data is ready to download" (GDPR Art. 15 + 20, BRAI-156 follow-up). The person asked for it,
 * so it always arrives in the app and by email (a transactional account mail, not a preference);
 * push follows the "Workspace" category. The link is the signed download, valid for 24 h and only
 * in the person's own session; after that it leads back to Profile → Your data.
 */
class DataExportReadyNotification extends CatalogNotification
{
    public const TYPE = 'data_export_ready';

    public function __construct(public readonly DataExport $export) {}

    public function catalogType(): string
    {
        return self::TYPE;
    }

    public function via(object $notifiable): array
    {
        $push = in_array(WebPushChannel::class, $this->channelsFor($notifiable, self::TYPE), true) ? [WebPushChannel::class] : [];

        return ['database', 'mail', ...$push];
    }

    protected function message(object $notifiable): array
    {
        return [
            'title' => 'Your data is ready to download',
            'body' => 'ZIP, '.Number::fileSize($this->export->file_size).' · the link works for '.DataExport::TTL_HOURS.' hours, only when you are signed in.',
            'url' => $this->export->downloadUrl() ?? route('profile.edit').'#your-data',
            'actor' => null,
            'project_name' => null,
            'subject_key' => null,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->toDatabase($notifiable);

        return BrandedMailMessage::account($notifiable, 'You get this email because you asked for a copy of your data in your Brainitive profile.')
            ->subject('Your Brainitive data is ready to download')
            ->headline('Your data is ready to download')
            ->preheader('A ZIP with the data Brainitive holds about your account.')
            ->line('The ZIP ('.Number::fileSize($this->export->file_size).') contains your account, your content and your settings as JSON files, with a README that explains each of them.')
            ->action('Download my data', $d['url'])
            ->line('The link works for '.DataExport::TTL_HOURS.' hours and only when you are signed in to Brainitive. After that the file is deleted; you can request a new copy in your profile.')
            ->line('If you didn’t ask for this, change your password and check Profile → Connected apps.');
    }
}
