---
title: Lead forms and leads
description: "Build multi-step lead forms in Brainitive, share them at /f/your-name or embed them on your site, and work every submission as a card on a leads board."
order: 62
---

# Lead forms and leads

Lead forms are multi-step forms for your website, ads or emails — demo requests, quotes, sign-ups. Every submission
becomes a card on the form's own **leads board**, where your team follows it up.

## Create a form

1. Open the project. Under **Customer-facing**, click **Add** next to **Lead forms**.
2. In **Create Lead Form**, pick a template or start blank. There are templates for demo request, contact sales, free
   trial, newsletter, agency brief, quote request, real estate, consultation, webinar, event lead capture, course
   enrolment and a job interest pool.

The form opens with five tabs: **Leads**, **Builder**, **Share**, **Analytics** and **Settings**. A badge next to the
name shows whether it is **Draft** or **Live**.

## Build the form

On the **Builder** tab, changes save automatically and the preview shows the form on desktop and mobile.

- **Steps and questions** – add as many steps as you need. Question types: Short text, Long text, Email, Phone,
  Number, Date, Choice cards, Multiple choice, Dropdown, Rating, Consent and Hidden.
- **Answer cards** – choice questions show cards with an icon or emoji.
- **Logic** – show a question, or a whole step, only for certain answers (is, is not, contains, is greater than, is less
  than, is answered, is not answered).
- **Save the answer as the lead's …** – on a question, choose whether its answer becomes the lead's email, phone,
  company and so on, so it shows on the lead card.
- **Style** – colours and presets, font, corners, button style, layout (card, split with an image, or full), progress
  bar or steps, your logo and a cover image.
- **Start**, **Buttons** and **After submit** – the intro text, button labels, and either a thank-you screen or a
  redirect to your own page.

### Hidden questions and campaign tracking

A **Hidden** question is filled from the link, for example `…/f/your-form?campaign=spring`. UTM tags in the link are
saved with each lead automatically.

### Consent checkbox

Under **Consent checkbox**, choose **None**, **Optional** or **Required** and write the **Checkbox text**. When a visitor
ticks it, the lead card records the consent with the time and the exact text they agreed to.

::: tip
Workspace admins can add your organisation's legal name, privacy policy and imprint under
**Workspace settings → Legal**; they are linked on your public forms.
:::

## Publish and share

Open the **Share** tab and click **Publish**. The form is then live:

- **Its own page** at `…/f/your-name`, to share in emails, ads and social posts. You can change the address, but links
  you already shared stop working.
- **Embed** – paste the snippet into your website (WordPress, Webflow, Wix, plain HTML). It resizes itself and shows
  the form without page chrome.

Drafts are never shown to visitors.

## Work your leads

The **Leads** tab is a board. Every submission appears as a card in the first column.

- Drag cards between columns, or add, rename and reorder columns. Mark a column as a **done column**.
- Search leads and filter by owner (**Anyone**, **Unassigned** or a person).
- Open a card to change its **Status** and **Owner**, read the **Answers**, the **Source** (campaign, UTM tags) and the
  **Consent** record, or delete the lead.

## Settings

The **Settings** tab is there for people who can edit the project:

- **Notify the team** – the members who get an email and a notification for every new lead.
- **Confirmation email** – sent to the lead right after they submit (`{name}` and `{form}` are filled in). It needs a
  question whose answer is saved as the lead's email.
- **Send leads to another tool** – Brainitive sends every new lead as JSON to a URL you choose (Zapier, Make, n8n,
  your CRM).
- **Receive leads from other tools** – create a token so other tools can push leads onto this board.
- **Delete form**.

## Analytics

The **Analytics** tab shows views, starts, submissions and conversion per day, and how far visitors get through your
steps. It counts unique visits without cookies or personal data.

## Notifications

New leads and leads assigned to you are in the **Leads** category of your
[notification settings](./notifications).

## Related

- [Helpdesk](./helpdesk) – for support requests instead of leads.
- [Booking pages](./booking-pages) – let people book a call right away.
