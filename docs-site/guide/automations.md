---
title: Automations
description: "Automate busywork in Brainitive with rules: triggers like issue created, moved or overdue, conditions, and actions like assign, move and notify."
order: 25
---

# Automations

Automations do routine work for you. A rule says: **when** something happens, **if** the issue or ticket matches your conditions, **then** do these actions. For example: *when a bug is created on the Support board, assign it to Sam and set the priority to High.*

## Where to find automations

Open the project page and scroll to **Tools → Automations**, then click **Open automations**. You need to be an admin of the project.

The **Automations** page lists the project's rules, each written as a sentence ("When … then …"), with a switch to turn it on or off and when it last ran. Below the list, **Run history** shows the last 50 runs of all rules.

## Create a rule

1. Click **New rule**.
2. Give it a **Rule name**, e.g. "Triage new bugs".
3. Choose the trigger under **When**.
4. Add conditions (optional).
5. Add one or more actions under **then**.
6. Click **Save rule**.

New rules start **switched off**. Test the rule first (see below), then turn it on in the list.

The editor always shows your rule in plain words under **Rule in words**, so you can read back what it will do.

## Triggers

| Trigger | Runs when |
|---|---|
| **Issue created** | A new issue or subtask is added to a board |
| **Issue moved** | An issue moves to another column. Optionally only when it moves **To column** X (or **To any column**). |
| **Issue assigned** | Someone is assigned to an issue |
| **Comment added** | Someone comments on an issue |
| **Due date passed** | An open issue passes its due date. Checked every few minutes, once per issue and due date. |
| **Ticket created** | A helpdesk ticket arrives, from an agent, the portal, the widget or email |

## Conditions

Conditions decide whether the rule should act. Each condition is **Field → Operator → Value**, for example *Priority · is any of · High, Highest*.

Choose at the top whether **All** of the conditions must match or **Any** of them is enough. A rule can have up to 10 conditions; no conditions means the rule acts every time the trigger fires.

### Fields

| Field | For |
|---|---|
| **Board** | Issues |
| **Helpdesk** | Tickets |
| **Column** | Issues and tickets |
| **Type** | Issues |
| **Priority** | Issues and tickets |
| **Labels** | Issues |
| **Assignee** | Issues and tickets |
| **Reporter** | Issues |
| **Title**, **Description** | Issues and tickets |
| **Due date**, **Start date** | Issues |
| Your **custom fields** | Issues |

### Operators

Which operators you get depends on the field:

| Kind of field | Operators |
|---|---|
| A single choice (board, column, type, priority) | **is**, **is not**, **is any of**, **is none of** (priority also **is empty**, **is not empty**) |
| Labels | **has**, **doesn't have**, **has any of**, **has all of**, **has none of**, **is empty**, **is not empty** |
| People (assignee, reporter) | **is**, **is not**, **is any of**, **is empty**, **is not empty** |
| Text (title, description) | **contains**, **doesn't contain**, **equals**, **starts with**, **is empty**, **is not empty** |
| Dates | **is empty**, **is not empty**, **is within the next** N days, **is before**, **is after**; due date also **is overdue** |
| Number fields | **=**, **≠**, **>**, **≥**, **<**, **≤**, **is empty**, **is not empty** |
| Checkbox fields | **is checked**, **is not checked** |

## Actions

Add up to 10 actions with **Add action**. They run in order.

| Action | What it does |
|---|---|
| **Assign to** | Assigns a project member |
| **Move to column** | Moves the issue |
| **Set priority** | Sets the priority |
| **Set type** | Changes the issue type |
| **Set due date** | Sets the due date to today or today + N days |
| **Add label** | Adds a label |
| **Add a comment** | Posts a comment. Write <code v-pre>{{item.key}}</code> or <code v-pre>{{item.title}}</code> to fill in the issue's key or title. |
| **Notify** | Notifies the assignee or a chosen member, with an optional message |
| **Create a linked issue on** | For tickets: creates an issue on a board, linked to the ticket |

Issue actions are only offered for issue triggers, ticket actions for **Ticket created**.

### Notifications from automations

People notified or assigned by a rule get a normal notification (in-app, push or email, depending on their settings). It says it came from the rule, for example *Automation "Triage new bugs" assigned you*. That works even when a rule assigns the person it runs as.

## Test a rule

Next to the editor, **Test on an issue** (or **Test on a ticket**) runs the rule on a real issue as a dry run: enter an issue key and see which conditions pass and what each action would do. Nothing is changed.

## Run history

Every run is logged: which conditions passed and what each action did (or why it was skipped). Open a run to see the details. The page lists the last 50 runs, the rule's own page the last 50 runs of that rule. Runs are kept for 30 days.

## Good to know

- **Rules run as a person:** the one who last saved the rule. If that person is removed from the project or suspended, the rule stops and the history tells you why. Save the rule again to run it as you.
- **No loops:** changes made by a rule don't trigger other rules.
- **Turn off instead of delete:** the switch in the list pauses a rule; **Delete rule** in the editor removes it for good.
