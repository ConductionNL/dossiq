# Proposal: end-of-day-manifest

## Why

"Close out your day" (DqDagAfsluiten) was a custom Vue view of about 880 lines. Ruben decided (20 October 2026) that it
moves onto the standard manifest setup, losing nothing, and that the day lists are worked out on the server so
"Recorded today" survives a reload. "Ready to record" (typed, not yet recorded notes) stays browser-only, as a figure
inside the list that holds the notes.

## What changes

- `GET /api/personal-queue/end-of-day` answers the day (EndOfDayService): `open` (candidates the reader opened today,
  by OpenRegister's per-reader read state, with no note of the reader's today), `recorded` (the reader's notes of today
  on the candidates, with the note and its time), `tomorrow` (not opened today, term ending by tomorrow, soonest first,
  two at most), `figures` (opened, with cases and tasks; notes recorded of the total), and the queue sources that could
  not be read as one sentence. Each row carries its kind label, route and the notes endpoint it records on (a case's
  on the case, a task's on the task).
- The page becomes a `dashboard`: a warning banner while a source could not be read; the open list as nextcloud-vue
  record-list `presentation: notes` (field per row, humaniq's hours leaf per row, record per row and "Close out your
  day" for all, the ready figure, the two empty texts); "Recorded today", "Your day", "First thing tomorrow" (hidden when
  nothing is due) and "How this works"; the breadcrumb to My work with today's date.
- EndOfDayView, its helpers (endOfDayHelpers, touchedToday, todayOf, humaniqIsPresent, HOURS_LEAF_ID) and
  `fetchEndOfDay` are removed.
