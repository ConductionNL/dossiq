# Design: split-picker-asks-the-policy

## D-1. One rule, asked earlier

The browser gets no copy of the policy. It asks the server, which asks
`CaseSplitPolicy` — the same object the write path consults. A rule
reimplemented in the dialog would be a second answer, and the first time the
two disagreed a handler would be told they may divide something the server
refuses.

## D-2. Empty starts empty

`allowed` starts as the empty list rather than as all three parts. Drawing
every section first and removing the forbidden ones when the answer lands
would flash a choice the handler may not make, and a flash is enough to be
clicked.

## D-3. Two empty states, two sentences

"This case type allows nothing to be divided" and "this case holds nothing of
what it allows" are different facts with different remedies: one is a call to
an administrator, the other is a case with no file on it yet. One sentence
over both sent the second handler to the first handler's meeting.

## D-4. A part that may not be divided is not read

When the policy forbids documents, the dialog does not fetch them. It is one
fewer request, but the reason is not speed: rows nobody may tick have no
business arriving in a browser at all.
