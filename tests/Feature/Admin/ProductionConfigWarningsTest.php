<?php

namespace Tests\Feature\Admin;

use App\Support\Admin\ProductionConfigWarnings;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Codex config review 9: a development .env on a production server is reported in the log.
 */
class ProductionConfigWarningsTest extends TestCase
{
    public function test_outside_production_nothing_is_reported(): void
    {
        config(['app.debug' => true, 'app.url' => 'http://brainitive.example.com']);

        $this->assertSame([], ProductionConfigWarnings::atBoot());
    }

    public function test_production_reports_debug_mode_and_a_plain_http_app_url(): void
    {
        $this->app['env'] = 'production';

        config(['app.debug' => false, 'app.url' => 'https://brainitive.example.com']);
        $this->assertSame([], ProductionConfigWarnings::atBoot());

        config(['app.debug' => true, 'app.url' => 'http://brainitive.example.com']);
        $this->assertSame(['app-debug', 'app-url-http'], array_keys(ProductionConfigWarnings::atBoot()));

        // the Docker quick start on localhost is not a finding
        config(['app.debug' => false, 'app.url' => 'http://localhost:8080']);
        $this->assertSame([], ProductionConfigWarnings::atBoot());
    }

    public function test_each_problem_is_logged_at_most_once_an_hour(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true, 'app.url' => 'https://brainitive.example.com']);
        Log::spy();

        ProductionConfigWarnings::reportAtBoot();
        ProductionConfigWarnings::reportAtBoot();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'APP_DEBUG=true in production'));
    }

    public function test_mail_to_the_log_mailer_is_reported_in_production_only(): void
    {
        Log::spy();
        $event = new MessageSending((new Email)->to('a@example.com')->subject('Reset')->text('link'), ['mailer' => 'log']);

        ProductionConfigWarnings::onMessageSending($event);
        Log::shouldNotHaveReceived('warning');

        $this->app['env'] = 'production';
        ProductionConfigWarnings::onMessageSending(new MessageSending((new Email)->to('a@example.com')->text('x'), ['mailer' => 'smtp']));
        Log::shouldNotHaveReceived('warning');

        ProductionConfigWarnings::onMessageSending($event);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'Mail is written to the log'));
    }
}
