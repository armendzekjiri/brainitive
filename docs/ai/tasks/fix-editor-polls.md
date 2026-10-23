# Polls that work (BRAI-13)

**Branch:** fix/editor-polls
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-13 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-15

## Problem
Inserting and editing polls was fixed earlier (`b6ed316`). Voting still wrote into the document: readers of a published doc couldn't save their vote, every vote was the string "user", and "already voted" lived only in React state, so you could vote again after a reload.

## Changes
- `poll_votes` table + `PollVote` + `PollVoteController` (`GET/POST /polls/{page|item}/{id}/{pollId}`): one vote per person, change or take back, results `{counts, total, my_vote}`, `view` policy on the doc / issue, throttled.
- `PollNode`: stable `pollId` (new polls get one; old ones derive it from option uids), votes from the server, results after voting, "Edit" only while the editor is editable, no voting without a scope.
- `RichLexicalEditor` `pollScope` prop (`PollScopeContext`): docs (`Pages/Show`) and issue descriptions (`ItemDetail`).

## Tests
`tests/Feature/Pages/PollVoteTest.php`. Browser: published doc, no Edit button, vote, reload keeps it, change vote, old embedded vote ignored.
