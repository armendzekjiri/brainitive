---
title: Helpdesk
description: "Run customer support in Brainitive: a ticket board, a public support page, a website widget, email to tickets, replies by email, assignment and SLAs."
order: 60
---

# Helpdesk

A helpdesk turns customer requests into tickets on a board. Customers write in through a support page, a widget on
your website or plain email, and your replies reach them by email.

## Create a helpdesk

Helpdesks live in a project.

1. Open the project. Under **Customer-facing**, click **Add** next to **Helpdesks**.
2. In **Create Helpdesk**, pick a template. Each one brings its own board columns and form fields, and shows a preview:
   Customer support, IT helpdesk, Bug reports, Returns, Website contact form, Quote request, Newsletter & waitlist,
   Partnerships, Press, Event registration, Appointment requests, Volunteers, Job applications, HR requests,
   Office & facilities, Feature requests and Beta sign-up.
3. Give it a **Helpdesk Name** and create it.

## Work with tickets

Open the helpdesk to see its tickets as a **Board** (columns) or a **List** (a table with key, subject, contact, status,
priority, SLA, assignee, created and replies). Drag tickets between columns to change their status.

The toolbar has a ticket search, a **Filter** (assignee: **Any user**, **Me**, **Unassigned**; and SLA state) and a sort:
**Board order** or **SLA: most urgent first**.

### Open a ticket

Click a ticket to see the customer's message, the form answers, attachments and the **Activity** with all replies.
On the right, under **Details**, set the **Status**, **Assignee** and **Priority**, and see the **SLA**, the **Contact** and
the dates. Custom fields are shown there too and can be edited.

### Reply

Write in **Add a reply…** and click **Reply**. The customer gets your reply by email (when the ticket has their email
address), and can answer on the ticket's own page or, if the helpdesk receives email, by replying to the email. Their
answers appear on the ticket right away.

### Assign tickets

Pick someone under **Assignee**. They get a notification. You can also let
[automations](./automations) assign tickets for you.

### Create a ticket yourself

Click **New ticket** to log a request that came in by phone or email. Enter the customer's email and name, the
request and any required fields of your support form, and set status, priority and assignee. Tick
**Email the customer a link to this ticket** if they should get it.

### Resolve a ticket

Moving a ticket into a "done" column asks for a **Message to the customer**. It's saved as a reply, and the customer
gets a "your ticket is resolved" email with the link. Leave the message empty to resolve without one.

## Let customers reach you

Open the helpdesk settings (the gear icon on the helpdesk) and use the **Embed** tab.

### Support page

Under **Support page**, choose a short **Address** like `…/support/acme` and click **Save**. The link opens your support
form as a full page — send it to customers, put it in your email signature or link it from your website.

### Widget on your website

- **Floating widget (click to open)** – copy the script snippet into your website. A button appears; visitors click it
  to open the form in a panel. Button text, position and brand colour come from the **General** tab.
- **Inline embed (always visible)** – copy the iframe snippet to show the form directly on a page.

When a customer submits the form, they get an email with a link to their ticket, where they can follow the
conversation and reply.

### Email to tickets

On the **Email** tab you can receive support email as tickets:

- **Incoming mail (IMAP)** – connect your support mailbox (server, username, password or app password). Every new
  email becomes a ticket. Under **Replies**, set the outgoing (SMTP) server, so replies go out from that address, in the
  same email thread. **Recently received** shows what came in and whether it became a ticket, a duplicate or was
  ignored.
- **Forwarding address** – forward your support mail to the helpdesk's own address instead. Brainitive shows the steps
  for Gmail and Outlook. Keep the address private: anyone who knows it can open tickets.

Auto-replies, mailing lists and strangers replying into a ticket are kept out.

::: tip Self-hosted?
A forwarding address only exists when your administrator has set up incoming mail — see
[Configuration](/self-hosting/configuration).
:::

## Helpdesk settings

| Tab | What you set |
|---|---|
| **General** | Name, description, brand colour, logo, welcome message, widget button text and position, and the form's texts (title, button label, thank-you message, hiding the Subject field) |
| **Columns** | The board columns, their colours and WIP limits |
| **Fields** | The form's fields: Name, Message and Attachments can be edited or hidden; add custom fields (text, rich text, number, date, dropdown, single choice, multiple choice, a yes/no checkbox or a file upload), required or not |
| **Embed** | Support page, floating widget and inline embed |
| **Email** | IMAP mailbox and forwarding address |
| **SLA** | Response and resolution targets |

### Service levels (SLA)

On the **SLA** tab, set a **First response** and a **Resolution** target per priority (in minutes, hours or days). Under
**When the clock runs** you can limit the clock to business hours (opening and closing time, days and time zone),
pause the resolution clock in a column such as "Waiting on customer", and get warned before a breach.

Tickets then show "Respond in …", "Breached" or "Met", and you can filter and sort by SLA. Workspace admins see SLA
results under **Insights → Helpdesk**.

## Notifications

New tickets, customer replies and ticket assignments land in your inbox. Choose how you hear about them under the
**Helpdesk** category in your [notification settings](./notifications).

## Related

- [Forms and leads](./forms-and-leads) – collect leads instead of support requests.
- [Automations](./automations) – create issues from tickets, assign and move them automatically.
