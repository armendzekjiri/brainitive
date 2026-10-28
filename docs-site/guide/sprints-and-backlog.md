---
title: Sprints and backlog
description: "Run Scrum in Brainitive: turn on sprints for a board, rank the backlog, start and complete sprints, and follow burndown and velocity reports."
order: 20
---

# Sprints and backlog

Boards in Brainitive work as Kanban boards by default. Turn on sprints and the same board becomes a Scrum board, with a **backlog**, planned sprints, an active sprint and **reports**.

## Turn on sprints

1. Open the board and click the gear icon next to its name (board settings).
2. Tick **Use sprints (Scrum)** and click **Save Changes**.

The board gets two new tabs, **Backlog** and **Reports**. From now on:

- the **Board** tab shows only the issues of the **active sprint**,
- new issues created on the board join the active sprint,
- subtasks always follow their parent issue into a sprint or the backlog.

You can turn sprints off again the same way.

## The backlog

Open the **Backlog** tab. It shows the active sprint, the planned sprints and, at the bottom, the backlog: everything not planned into a sprint yet.

- **Rank** issues by dragging them up or down. The order is shared with everyone.
- **Plan** an issue into a sprint by dragging it from the backlog into the sprint (or back).
- **Search backlog** filters the list.
- Add an issue straight to the backlog by typing what needs to be done.

### Create a sprint

Click **Create sprint**. Use the sprint's menu to **Edit sprint**:

| Field | |
|---|---|
| **Sprint name** | e.g. "Sprint 14" |
| **Sprint goal** | What should this sprint achieve? |
| **Duration** | 1, 2, 3 or 4 weeks, or **Custom** |
| **Start date**, **End date** | Filled in from the duration, or set by hand |

You can plan several sprints ahead.

### Start a sprint

1. Drag the issues for the sprint into it.
2. Click **Start sprint** on that sprint, check name, dates and goal, and confirm.

Only one sprint can run per board at a time. A sprint needs at least one issue to start.

### Delete a sprint

Open the sprint's menu → **Delete sprint**. Its issues go back to the backlog; nothing else is deleted.

## During the sprint

The **Board** tab shows a bar above the columns with the sprint name, its goal, the days left and **Complete sprint**. If no sprint is running, the board says so and points you to the backlog.

## Complete a sprint

1. Click **Complete sprint** on the board.
2. Under **Move open issues to**, choose the next planned sprint or the **Backlog**.
3. Confirm.

The issue history records every sprint change.

## Reports

The **Reports** tab has two charts:

- **Burndown** for a sprint you choose: the work remaining day by day against the ideal guideline, plus **Scope**, **Done** and **Remaining**. Switch between **Chart** and **Table**.
- **Velocity**: committed vs. completed work for each of the last 7 completed sprints, and the **Avg. velocity**. It shows up after your first completed sprint.

Both count **story points** when the sprint's issues have points, otherwise the number of issues. Subtasks aren't counted separately.

::: tip
Give issues story points in the **Points** field of the issue panel to get a burndown in points. See [Boards and issues](./boards-and-issues#the-issue-panel).
:::
