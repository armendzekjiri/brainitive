# Skill: Add a CRUD feature (end-to-end)

The golden path for a new entity. Mirrors how Boards / Snippets / Task Lists are built.
Read [`CONVENTIONS.md`](../CONVENTIONS.md) first; locate the parent module in
[`ARCHITECTURE.md`](../ARCHITECTURE.md).

Assume a new entity `Widget` owned by a `Project`.

## 1. Migration
`database/migrations/xxxx_create_widgets_table.php`
```php
Schema::create('widgets', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('project_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('slug')->unique();   // if slug-bound
    $table->json('settings')->nullable();
    $table->timestamps();
    $table->softDeletes();
});
```
Keep it cross-DB safe (default SQLite). Add a factory in `database/factories/`.

## 2. Model — `app/Models/Widget.php`
```php
class Widget extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['uuid', 'project_id', 'name', 'slug', 'settings'];

    protected function casts(): array { return ['settings' => 'array']; }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($w) {
            $w->uuid ??= (string) Str::uuid();
            $w->slug ??= Str::slug($w->name);
        });
    }

    public function getRouteKeyName(): string { return 'slug'; }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
}
```
Add the inverse relation on `Project` (`widgets(): HasMany`).

## 3. Policy — `app/Policies/WidgetPolicy.php`
Implement `viewAny, view, create, update, delete` using
`$user->projectRole($project)` / `hasProjectAccess()`. Then **register it** (this repo does
not auto-discover) in `app/Providers/AppServiceProvider.php::boot()`:
```php
Gate::policy(Widget::class, WidgetPolicy::class);
```

## 4. Controller — `app/Http/Controllers/WidgetController.php`
```php
public function store(Request $request, Workspace $workspace, Project $project)
{
    $this->authorize('create', [Widget::class, $project]);
    $data = $request->validate([
        'name' => 'required|string|max:255',
        'settings' => 'nullable|array',
    ]);
    $widget = $project->widgets()->create($data);
    return back()->with('success', 'Widget created');
}

public function show(Workspace $workspace, Project $project, Widget $widget)
{
    $this->authorize('view', $widget);
    return Inertia::render('Widgets/Show', [
        'widget' => $widget->load('project'),
    ]);
}
```
Inline validation, `$this->authorize(...)`, eager load, Inertia render or `back()`.

## 5. Routes — `routes/web.php`
Add inside the `projects/{project}` group (see the boards/task-lists block for the pattern),
**named**, scoped:
```php
Route::post('widgets', [WidgetController::class, 'store'])->name('widgets.store');
Route::get('widgets/{widget:slug}', [WidgetController::class, 'show'])->name('widgets.show');
// ...update, destroy
```

## 6. Frontend type — `resources/js/types/index.d.ts`
```ts
export interface Widget {
    id: number;
    uuid: string;
    name: string;
    slug: string;
    settings: Record<string, unknown> | null;
}
```

## 7. Page — `resources/js/Pages/Widgets/Show.tsx`
See the [add-inertia-page skill](./add-inertia-page.md).

## 8. Tests — `tests/Feature/WidgetTest.php`
`RefreshDatabase`, factories, `actingAs($user)`. Cover create/show/update/delete, each
policy method, validation rejects bad input, authz denies non-members, soft delete, slug
uniqueness.

## 9. Update docs & gate
Add a **Widgets** row to [`ARCHITECTURE.md`](../ARCHITECTURE.md). Run the
[gate](../WORKFLOW.md#5--the-gate-then-pr).
