<?php

namespace Tests\Unit;

use App\Http\Middleware\ReadOnlySession;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * A background fetch must not write the session back: it would overwrite the flash / validation
 * errors a parallel form submit just stored (BRAI-96).
 */
class ReadOnlySessionTest extends TestCase
{
    public function test_the_session_is_read_but_never_written_back(): void
    {
        $handler = new ArraySessionHandler(120);
        $session = new Store('test', $handler, 'session-id-1234567890123456789012345678');
        $handler->write($session->getId(), serialize(['errors' => 'from the form submit']));
        $session->start();

        $request = Request::create('/api/page-sticky-notes');
        $request->setLaravelSession($session);

        (new ReadOnlySession)->handle($request, function () use ($session) {
            $session->forget('errors'); // what aging flash data does on an ordinary request
            $session->put('stale', true);

            return new Response('[]');
        });
        $session->save(); // what StartSession does after the response

        $this->assertSame(['errors' => 'from the form submit'], unserialize($handler->read($session->getId())));
    }

    public function test_the_background_fetches_use_it(): void
    {
        // fired on page load / polled: sticky notes, the bell's count and its list
        foreach (['page-sticky-notes.index', 'notifications.unread-count', 'notifications.index'] as $name) {
            $this->assertContains(ReadOnlySession::class, Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }
}
