# Design: case-number-and-favourites

## D-1: the format keeps four digits, not the six the brief offered

`{seq:4}`, not `{seq:6}`. The shape `YYYY-NNNN` is not cosmetic in dossiq: the
inbound mail matcher's default pattern is `/\d{4}-\d{4}/`, the demo cases are
seeded in it, and `tests/e2e/case-identity.spec.ts` asserts it. Widening the
pad to six would not fail loudly. `\d{4}-\d{4}` matches the first eight
characters of `2026-000042`, so the matcher would go on matching, on the wrong
prefix, and link mail to whatever case holds `2026-0000`. A silently wrong
match on a statutory case file is worse than a narrow number.

`{seq:n}` grows past its pad rather than wrapping, so a year with more than
9,999 cases keeps counting and the number stays unique. That is the same
answer `CaseNumberService::PAD` already gives.

## D-2: `{year}` is the creation year, where the calculation used the start date

The retired expression read `{"year": {"prop": "startDate"}}`. The annotation
renders `{year}` from the creation moment and takes no property. A case
created on 2 January 2027 carrying a December 2026 start date therefore gets
`2027-…` where it used to get `2026-…`.

Named rather than worked around. The number identifies the record, not the
period it concerns, and a case created in January against last year's start
date would otherwise be issued a number out of last year's exhausted range.
The archived change's own scenario, "a case posted without an identifier gets
a number whose year is the year of its start date", is the one line of
REQ-CM-25 this change rewrites.

## D-3: one mechanism, so the calculation entry goes

Leaving `x-openregister-calculations.identifier` in place beside the
annotation would leave two writers on one field, each correct on its own and
neither able to say what the other did. The calculation runs on the create
path and materialises; the listener fills only what is empty. Whichever ran
first would win and the counter would then be advanced by the loser, which is
a number spent on nothing.

So the calculation entry is removed and the annotation is the only writer.

## D-4: `CaseNumberService` stays, with a new reason

The service was written as the hedge against an OpenRegister whose calculation
engine did not know the `sequence` operator. That hedge now points at a
different version boundary: an OpenRegister that predates
`x-openregister-generated` ignores an annotation it does not read, silently,
in the register, and every case is filed without a number.

The service is unchanged in behaviour. It fills only an empty `identifier`, in
`YYYY-NNNN` off the case's start year, at MAX + 1 of that year rather than
COUNT + 1. Its docblock names the new annotation, because a comment naming a
mechanism that no longer exists is how the next reader deletes the wrong file.

## D-5: the complaint counter is its own, not the case's

OpenRegister lets two schemas share a counter by naming it. Complaints do not
want that. A klachtnummer is quoted to a citizen under the Awb and reads as a
complaint count; drawing it from the case counter would make it jump in
hundreds between two complaints and tell anybody holding two letters roughly
how many cases the municipality filed in between. Sequence `complaint`,
format `KL-{year}-{seq:4}`, which is exactly the shape
`generateComplaintNumber` produced.

## D-6: the refusal is OpenRegister's sentence, not a dossiq one

The listener answers a changed identifier with
`"identifier" is a generated identifier and cannot be changed. It was issued
as "2026-0042".` under the code `generated-identifier-frozen`. dossiq renders
that string. A dossiq paraphrase would be a second answer to the same
question, and it would be the one on screen when the two disagreed.

The form cannot produce the refusal on its own: `identifier` is `readOnly` on
the schema and `editable: false` on the case page, so no dossiq surface sends
a changed number. The refusal is reachable from the API and from an import,
which is where the scenario lives.

## D-7: the star is per user, so it is never a field of the case

The same argument the unread state already carries. A star is a fact about a
reader, not about the case, and writing it into the object would cut a version
and an audit entry every time somebody changed their mind. OpenRegister keeps
it in its own table and reports it as `@self.favourite` on every read and
every list row, so dossiq stores nothing and computes nothing.

## D-8: a widget, because the star is two verbs

The case page's star is a custom widget and not a declarative header action,
and the reason is mechanical. `CnActionButtons`' `toggle` type writes with one
method: it flips a boolean and `PUT`s it. Starring is `PUT` and unstarring is
`DELETE` on the same path, so a toggle action can express half the gesture.
A `handler` header action is no better, because `dispatchAction` spreads
`action.args` verbatim and resolves no tokens in them, so the handler would be
called with no case to act on.

On the list the same gesture is a row action handler, which is what
`markCaseUnread` already is and for the same reason: the row dispatcher knows
`navigate`, `open-page` and a handler name, and nothing else.

## D-9: the strip sits above the unread strip

The case page already reads top to bottom as identity, then what changed, then
what is wrong. The star belongs with identity, so it goes directly under the
tiles and above the unread strip. Everything below moves down one row. The
layout tests assert the order of the strips relative to each other and to the
panels rather than absolute rows, which is why they survive the shift.

`tests/vitest/caseLifecycleManifest.spec.js` is the exception: it pins the
list of rows between the tiles and the panels by name, and it was already red
on `development` because `case-attention` landed without being added to it.
The list is corrected here, with both strips, because this change adds a third
and cannot leave the assertion naming one.

## D-10: tiles read the lens, not a saved view

The two dashboard tiles are `object-table` widgets whose `source.filter`
carries `_favourite: true` and `_recent: true`. That is the same shape the
`_unread` chip already uses on `#Cases`, so the lens travels through the
filter bag with no dossiq code between the page and the query.
