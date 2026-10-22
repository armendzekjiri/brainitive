# Friendly error pages (BRAI-47)

**Branch:** fix/error-pages
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-47 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-132

## Approach
`bootstrap/app.php` `withExceptions()->respond()` renders `Pages/Error.tsx` (standalone, themed) for 401/403/404/405/410/429/500/503. 5xx only when debug is off, so local development keeps the detailed page. JSON requests are untouched. A 419 redirects: guests to login with "session expired", signed-in users back with a flash error. `Route::fallback` makes unknown URLs run through the web middleware (session). The responder shares `HandleInertiaRequests` props itself, because route-model binding fails before that middleware. The page offers Go back plus "Open {workspace}" / dashboard for users, and home / sign in for guests.

## Tests
`tests/Feature/ErrorPagesTest.php`: guest and user 404, 403, missing model (with auth), 500 without details, 419 for guest and user, API JSON. Browser: light (guest) and dark (signed in).
