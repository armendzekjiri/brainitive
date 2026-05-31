# Skills — Task Recipes

Step-by-step playbooks for common tasks, written so an agent does the task **the project's
way** without reverse-engineering it. Each recipe assumes you've read
[`CONVENTIONS.md`](../CONVENTIONS.md) and located the area in [`ARCHITECTURE.md`](../ARCHITECTURE.md).

| Recipe | Use when |
|---|---|
| [add-crud-feature.md](./add-crud-feature.md) | Adding a new entity end-to-end (model → migration → policy → controller → routes → page → tests) |
| [add-inertia-page.md](./add-inertia-page.md) | Adding/changing a frontend page wired to a controller + typed props |
| [add-mcp-tool.md](./add-mcp-tool.md) | Exposing a capability to AI clients via the MCP server |
| [add-notification.md](./add-notification.md) | Notifying a user in-app + by browser push (and/or email) |
| [track-work-in-brainitive.md](./track-work-in-brainitive.md) | Tracking the task on the Brainitive board when MCP is connected (create card → Testing) |

Adding a new recipe? Keep it concrete (real file paths, real snippets from this repo), link
it from this index, and follow the [WORKFLOW](../WORKFLOW.md) "update docs" step.
