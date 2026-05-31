# Board Item Cards — Profile Images

## Objective

Display user profile images (or initials/avatars) on all board item cards so assignees are easy to recognize and collaboration is clearer.

---

## Acceptance Criteria

- [x] **AC1** — On the board view, each item card shows the assignee’s profile image when they have one; otherwise it shows their initial in a fallback avatar.
- [x] **AC2** — Profile images use the user’s existing profile picture (same as elsewhere in the app: `avatar` → `avatar_display` URL).
- [x] **AC3** — List view (table) shows the same: profile image or initial for the assignee column.
- [x] **AC4** — Assignee dropdown in the item edit modal shows profile images next to names for consistency.
- [x] **AC5** — No breaking changes: unassigned cards still show the dashed-user icon; users without a photo still show initials.

---

## Technical Implementation (Done)

### Backend

- **BoardController** (board show + item show):
  - Load assignee with `avatar`: `assignee:id,name,email,avatar` so the User’s `avatar_display` append is populated.
  - Load project users with `avatar`: `users.id, name, email, avatar` so the assignee dropdown can show avatars.

### Frontend

- **Boards/Show.tsx**
  - **ItemCard:** Use `AvatarImage` when `item.assignee.avatar_display` is set; keep `AvatarFallback` with first letter of name.
  - **ListView:** Same for the assignee cell: `AvatarImage` + `AvatarFallback`.
  - **Item edit modal — Assignee Select:** For each `projectUsers` option, show `AvatarImage` when `user.avatar_display` is set, else `AvatarFallback` with initial.
  - Import: `AvatarImage` from `@/components/ui/avatar`.

### Data

- **User** (types): already has `avatar?: string | null` and `avatar_display?: string | null`.
- **Item.assignee**: type `User`; backend now includes `avatar` so `avatar_display` is present when the user has a profile photo.

---

## Complexity

- **Low.** Reuses existing User avatar pipeline and existing Avatar UI; only adds `avatar` to a few eager-load selects and uses `AvatarImage` in three places (card, list, dropdown).

---

## Effort Estimate

- **Implementation:** ~0.5–1 hour (backend select changes + frontend AvatarImage usage).
- **Testing:** ~0.5 hour (board cards, list view, assignee dropdown, unassigned / no-photo cases).
- **Total:** ~1–1.5 developer hours.
