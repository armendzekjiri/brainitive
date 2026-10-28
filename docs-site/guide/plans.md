---
title: Plans (Gantt)
description: "Build Gantt plans in Brainitive: rows and groups, bars and milestones, dependencies, rows linked to board issues, templates, sharing and export."
order: 40
---

# Plans (Gantt)

A **plan** is a Gantt chart for the big picture: phases, bars, milestones and who does what, on a timeline. A spreadsheet on the left and the timeline on the right scroll together. Rows can be linked to real issues on your boards.

## Create a plan

1. Click **Add content** under the project in the sidebar and choose **Plan**.
2. Give it a name and pick a starting point:

   | Template | |
   |---|---|
   | **Blank plan** | An empty sheet |
   | **Project plan** | Initiation, planning, execution, closing |
   | **Product launch** | Research, build, marketing, launch |
   | **Software release** | Sprints, QA and a release milestone |
   | **Marketing campaign** | Strategy, content, channels |
   | **Event planning** | Concept, venue, promotion, event day |
   | **Workshop series** | Prepare, run and follow up workshops |

Templates are laid out from the current week. A project can have as many plans as you like.

## The sheet

Each row has the columns **Task**, **Who**, **Status**, **Start**, **End**, **Days** (working days), **Progress** and **Notes**. Show or hide columns with **Columns**.

- **Type straight into a cell.** <kbd>Enter</kbd> or <kbd>F2</kbd> edits a cell; <kbd>Enter</kbd> again goes to the next row.
- **Add rows** with **Add row**, **New group** or **Add milestone**, or right-click a row (**Insert row above**, **Insert row below**, **Add sub-row**).
- **Groups:** <kbd>Tab</kbd> / <kbd>Shift+Tab</kbd> indents and outdents rows. Group rows collapse and show a summary bar with rolled-up dates and progress.
- **Reorder** with <kbd>Alt+↑</kbd> / <kbd>Alt+↓</kbd>, or drag a row by its number.
- **Copy and paste** rows with <kbd>Ctrl+C</kbd> / <kbd>X</kbd> / <kbd>V</kbd>, also straight from Excel or Google Sheets. <kbd>Ctrl+D</kbd> duplicates rows.
- **Undo / redo** with <kbd>Ctrl+Z</kbd> / <kbd>Ctrl+Y</kbd>.
- **Filter rows** by status or person; **Clear filters** shows everything again.

The **Shortcuts** button lists all keyboard shortcuts.

## Bars and milestones

- **Draw a bar** by dragging on the timeline. A single click adds one unit (a day, week or month, depending on the zoom).
- **Move** a bar by dragging it, **resize** it at its ends. A row can have several bars.
- **Double-click a bar** to edit it: dates, label, colour (10 colours) and progress.
- **Milestones** show as diamonds. Use **Add milestone**, or **Convert to milestone** on a bar.

### Zoom

Switch between **Day**, **Week**, **Month** and **Quarter**. The timeline shows a today line, shaded weekends and week numbers. **Today** jumps back to the current date.

## Dependencies

Drag from the dot (●) at the end of a bar onto another bar. The link is drawn as an arrow and listed in the bar editor under **Dependencies** (**Comes before** / **Starts after**). Click an arrow to select it and press <kbd>Delete</kbd> to remove it.

## Rows linked to issues

Connect the plan to your boards:

- **Issues → Add issues as rows:** search the project's issues and add them. Each issue becomes a linked row with its dates, status and assignee.
- **Issues → Create issues from rows:** pick a board, and each selected row becomes an issue. Start and end become the issue's start and due date; a matching **Who** becomes the assignee.
- **Right-click a row → Link to existing issue…** links a row to an issue that already exists. **Unlink issue** removes the link.

Linked rows show the issue key and the board status, and follow the issue: title, assignee, dates and progress update when the plan opens, or right away with **Refresh from board**. The other way round works too: moving or resizing a linked bar, or renaming the row, updates the issue, and the issue's history shows the change. Issues deleted on the board are unlinked automatically.

## Saving, versions and working together

- Plans **save automatically** ("All changes saved").
- When teammates change the plan, you see their changes as they happen. Your own unsaved edit is never overwritten: if both of you changed it, a banner lets you choose **Load latest** or **Overwrite with mine**.
- **History** keeps versions. Click **Save version** to keep the current state as a checkpoint; open **Plan history** to preview any version and restore it.

## Import, export and print

- **Export → Excel / CSV** downloads the rows.
- **Import rows…** reads rows from a CSV file or pasted from a spreadsheet. You can add them or **Replace all rows**.
- **Print / PDF** opens a print view of the whole plan.

## Share a read-only link

Click **Share** → **Share a read-only link** to show the plan to people outside Brainitive, for example a client. They see the chart, nothing else. Turn the link off or replace it any time.

## Who can do what

Everyone in the project can open plans. Project **editors** and **admins** change them; project admins delete them. See [Roles and permissions](./workspaces-and-projects#roles-and-permissions).
