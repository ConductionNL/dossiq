# Design: simple-list-and-dashboard

## D-1 The five views

| view | filter |
| --- | --- |
| All | as today (the default lens) |
| Mine | as today |
| Due this week | as today |
| Waiting on the applicant | as today |
| Woo requests | `caseType` = the seeded Woo request case type, not hidden, not a draft |

The first four exist. The overlay adds `showCount` to them by label, appends
Woo requests and moves the five to the front. `quickFilterMaxVisible` goes from
4 to 5, so exactly these five are pills. No lens is removed: Archived and My
drafts are each the only way back to what they show.

## D-2 The six columns

`identifier`, `title`, `caseType`, `status` (both with the formatter and widget
they have today), `assignee` as the library's `avatar` cell, `deadline` as the
library's `date` cell with
`[{ lte 0: error }, { lte 5: warning }]`.

## D-3 Late is the same everywhere

| surface | rule |
| --- | --- |
| list, deadline column | `lte 0` error, `lte 5` warning |
| board card | `lte 0` error, `lte 3` warning |
| dashboard, week strip | `lateWhen: lte 0` |

Today counts as late in the simple structure. In the full structure the board
card keeps its own rule: late after the deadline, a warning within three days.

The board is `WorkflowBoardView`, dossiq's own component. The library's
`dueRule` belongs to the library's board, so it does not reach this one. The
rule is declared on the `WorkflowBoard` page in the same shape, and `CaseCard`
reads it from the built manifest through `cardDueSeverity()`.

## D-4 The dashboard

| row | widget | data |
| --- | --- | --- |
| 0 | greeting | the signed-in person, today's date |
| 2 | First today | shown when an open case of mine is past its deadline or ends today; counts them and links to them |
| 4 | four counts | my open cases; due this week (the list view's own filter); awaiting applicant; closed this month |
| 6 | Deadlines this week | my open cases by `deadline` |
| 13 | My tasks | the My work page's own task widget |
| 10 | My cases per step | my open cases grouped by `statusRole` |
| 13 and on | everything the dashboard held | unchanged, 18 rows down |

Every count is a filter on fields the case carries. "Mine" is `assignee: @me`.
