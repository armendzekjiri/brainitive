# feat/outgoing-webhooks — BRAI-104 Outgoing webhooks per workspace (v1.39.0, ticket 3)

Spec and contract: [`release-v1.39.0.md`](./release-v1.39.0.md) → "3. BRAI-104" and "Domain events".

## Plan
1. **Schema:** `webhooks` (workspace, name, url, encrypted hidden secret, events json, active,
   failure_count, disabled_at, last_delivery_at, created_by) and `webhook_deliveries` (uuid, event,
   event_id, payload, status, attempts, response_code/body ≤ 2 KB, duration_ms, error, delivered_at),
   prunable after 30 days (`routes/console.php`).
2. **`DomainEvents::dispatch`:** envelope built at call time, then `DB::afterCommit` → one pending
   delivery per active, subscribed webhook → `DeliverWebhook`. Never throws into the caller.
   `DomainEvents::emit()` = same, but the payload closure only runs when a webhook listens.
3. **`DeliverWebhook`:** `OutboundUrl::options()` (private targets only with the e2e-only flag),
   timeout 10 s, signed `sha256=hmac(secret, "{timestamp}.{body}")`, 5xx / network → retry
   (30 s, 2 min, 10 min), 4xx → failed. 10 consecutive failed deliveries → auto-disable + in-app
   `webhook_disabled` notification to the admins (category `workspace`).
   **Sync queue (staging, e2e):** web requests dispatch the job `afterResponse()`; one attempt,
   Redeliver from the UI.
4. **Hook points:** ItemObserver (created / updated / moved / completed / assigned / deleted),
   ItemComments, Page publish / publishChanges, SprintLifecycle, TicketController (created,
   replied, resolved), LeadIntake, TimeEntry::booted, ReportFinalizer finalize / markPaid.
   Payload builders live in `App\Support\Events\EventPayloads` so the hook lines stay one-liners.
5. **UI:** `Pages/Workspaces/Webhooks.tsx` (admins), linked from workspace settings. List,
   create/edit dialog with grouped event picker, secret shown once + reveal + rotate, Send test
   (`ping`), deliveries per webhook (expandable, Redeliver), docs panel (envelope, Node + PHP
   verification).
6. **Tests:** PHPUnit `tests/Feature/Webhooks/*`; e2e `e2e/tests/webhooks.spec.ts` with a
   Playwright-side receiver on 127.0.0.1 (`WEBHOOKS_ALLOW_PRIVATE_TARGETS`, honoured only when
   `APP_ENV=e2e`).
7. Docs: ARCHITECTURE + KNOWLEDGE.
