# Skill: Add an Inertia page + frontend type

How a controller renders a typed React page. No API layer — controller props flow straight
into the page component.

## 1. Controller renders the page
The page name maps to `resources/js/Pages/<path>.tsx`:
```php
return Inertia::render('Widgets/Show', [
    'widget' => $widget->load('project'),
    'can'    => ['update' => $request->user()->can('update', $widget)],
]);
```
Pass only what the page needs; eager load relations the page reads.

## 2. Type the props — `resources/js/types/index.d.ts`
Every entity interface lives here (single source for types). Add the entity if new:
```ts
export interface Widget {
    id: number;
    uuid: string;
    name: string;
    slug: string;
}
```

## 3. The page component — `resources/js/Pages/Widgets/Show.tsx`
```tsx
import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import type { Widget } from '@/types';

interface Props {
    widget: Widget;
    can: { update: boolean };
}

export default function Show({ widget, can }: Props) {
    const { data, setData, put, processing, errors } = useForm({ name: widget.name });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        put(route('widgets.update', { workspace: '...', project: '...', widget: widget.slug }));
    };

    return (
        <AppLayout>
            <Head title={widget.name} />
            {/* shadcn/ui + Tailwind; cn() for conditional classes; Lucide icons only */}
        </AppLayout>
    );
}
```

## Rules to respect
- Wrap in `AppLayout` (app) or `GuestLayout` (auth). Always render `<Head title=…>`.
- shadcn/ui from `@/components/ui/*`; merge classes with `cn()` from `@/lib/utils`.
- **Lucide React only** for icons (`h-4 w-4` / `h-5 w-5` / `h-6 w-6`).
- Navigate with Ziggy: `route('name', { param })`, `<Link href={route(...)}>`, `router.*`.
- Forms: Inertia `useForm` for simple CRUD, react-hook-form + Zod for complex. Always show
  server `errors`.
- Feedback: Sonner `toast.success(...)`; flash via `usePage().props.flash`.
- Brand colors / styling patterns: see [`CONVENTIONS.md`](../CONVENTIONS.md#ui--styling).

## Gate
`npx tsc --noEmit` must pass (the type wiring is the most common break), then the full
[gate](../WORKFLOW.md#5--the-gate-then-pr).
