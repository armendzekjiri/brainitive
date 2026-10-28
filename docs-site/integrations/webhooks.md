---
title: Outgoing webhooks
sidebarTitle: Webhooks
description: Send Brainitive events (issues, pages, sprints, helpdesk, leads, time, billing) to Zapier, Make, n8n or your own service with signed webhooks.
order: 80
---

# Outgoing webhooks

Webhooks send an HTTP `POST` to your URL whenever something happens in a workspace. Use them to connect
Zapier, Make, n8n, Slack (through one of those tools) or your own service.

## Add a webhook

1. As a workspace admin, open **your avatar → Workspace settings → Webhooks**.
2. Click **Add webhook**. Enter a name and the URL, and choose **All events** or only the ones you need.
3. Copy the **signing secret** shown after saving. Your receiver uses it to verify requests. Admins can show it
   again later with **Reveal secret**, or replace it with **Rotate secret**.
4. Use **Send test** to send a `ping`, and open the webhook's deliveries to see each attempt and its answer.

The URL must use `https` and point to a public address.

## Events

| Area | Events |
|---|---|
| Issues | `item.created`, `item.updated`, `item.moved`, `item.completed`, `item.assigned`, `item.commented`, `item.deleted` |
| Pages | `page.published` |
| Sprints | `sprint.started`, `sprint.completed` |
| Helpdesk | `ticket.created`, `ticket.replied`, `ticket.resolved` |
| Leads | `lead.created` |
| Time tracking | `time_entry.created` |
| Billing | `billing_report.finalized`, `billing_report.paid` |

Bulk imports don't send a webhook for each record.

## Payload and signature

Every request has a JSON body:

```json
{
  "id": "9b1d…",
  "event": "item.created",
  "occurred_at": "2026-10-28T09:15:00+00:00",
  "workspace": { "id": 1, "slug": "acme", "name": "Acme" },
  "actor": { "id": 7, "name": "Jane Doe" },
  "data": { }
}
```

Every request also carries these headers:

| Header | Value |
|---|---|
| `X-Brainitive-Event` | The event name |
| `X-Brainitive-Delivery` | A unique delivery ID |
| `X-Brainitive-Timestamp` | Unix seconds |
| `X-Brainitive-Signature` | `sha256=` + hex HMAC-SHA256 of `"{timestamp}.{body}"` with your signing secret |

To verify a request, compute the HMAC over the timestamp, a dot and the raw body, then compare it with the
signature using a constant-time comparison.

## Retries and failures

- Any `2xx` answer counts as success.
- `5xx`, `429` and network errors are retried after 30 seconds, 2 minutes and 10 minutes. Other status codes
  fail right away.
- After 10 failed deliveries in a row, the webhook is turned off and the workspace admins are notified. A
  successful delivery resets the count.

On a self-hosted instance, deliveries go through the queue, so make sure the queue worker is running (the Docker
setup includes one).
