# Changelog

All notable changes to Brainitive are documented here.

---

## [Unreleased] – 2026-02-28

### Fixed

- **Editor: content wiped on fast Edit → Save / Update** — Fixed a race condition in `RichLexicalEditorFull` and `RichLexicalEditor` where `HtmlOnChangePlugin` could fire `onChange` with an empty string while `InitialContentPlugin` was still clearing and repopulating the Lexical tree. Replaced the fragile "skip first N updates" counter with a shared `isInitializingRef` that is held `true` during the entire init update and released only after the `onUpdate` callback confirms the tree is fully written. This affected both the **Items description** (Edit/Save flow) and the **Pages document** (Edit/Update flow). ([`RichLexicalEditorFull.tsx`](resources/js/components/editor/RichLexicalEditorFull.tsx), [`RichLexicalEditor.tsx`](resources/js/components/editor/RichLexicalEditor.tsx))

- **Pages: Update button sent empty content** — `currentHtmlRef` was initialised to `null`, so clicking **Edit → Update** immediately (before any keystroke) called `handleExplicitUpdate` with an empty string, wiping the page. Fixed by initialising the ref with the current saved HTML. Also added an `isEditing` guard to `handleUpdateContent` to prevent spurious auto-saves triggered during the view→edit editor remount. ([`Pages/Show.tsx`](resources/js/Pages/Pages/Show.tsx))
