# GitHub Copilot Instructions — Brainitive

The full architecture, conventions, and patterns for this repo live in the shared AI
knowledge base (index: [`docs/ai/README.md`](../docs/ai/README.md)). **Read these before
suggesting code:**

- [`docs/ai/ARCHITECTURE.md`](../docs/ai/ARCHITECTURE.md) — module map + file pointers
- [`docs/ai/CONVENTIONS.md`](../docs/ai/CONVENTIONS.md) — coding rules & patterns
- [`docs/ai/WORKFLOW.md`](../docs/ai/WORKFLOW.md) — feature/fix workflow, definition-of-done
- [`docs/ai/skills/`](../docs/ai/skills) — task recipes
- [`docs/ai/KNOWLEDGE.md`](../docs/ai/KNOWLEDGE.md) — decisions, gotchas, known issues

## Essentials Copilot should always respect

- **Laravel 12 + Inertia.js v2 + React 18 + TypeScript** monolith. No API layer —
  controllers return `Inertia::render('Page', [...])`.
- Models: always `$fillable` (never `$guarded`); `casts()` method; `boot()` generates
  `uuid`/`slug`; explicit relationship return types.
- Controllers: inline `$request->validate([...])`; `$this->authorize(...)`; eager load to
  avoid N+1.
- Policies are **explicitly registered** in `AppServiceProvider` (not auto-discovered) — a
  new policy does nothing until you add `Gate::policy(...)`.
- Routes: always named (Ziggy); app routes behind `['auth','verified']`.
- Frontend: shadcn/ui (new-york) from `@/components/ui/*`; merge classes with `cn()`;
  **Lucide React only** for icons; Ziggy `route()` in JS; Sonner for toasts.
- Types: all entity interfaces in `resources/js/types/index.d.ts`. Path alias `@/` →
  `resources/js/`.
- Brand colors: Blue `#0E83DC`, Slate `#485E72`, Green `#69A748`, Gray `#C0C5C0`,
  Page BG `#F4F6F8`.

## Before considering a change done

```bash
./vendor/bin/pint --test && npx tsc --noEmit && php artisan test && npm run build
```
