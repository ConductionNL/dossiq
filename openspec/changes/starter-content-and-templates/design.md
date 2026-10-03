# Design: starter-content-and-templates

## D-1. The shipped set is a named, versioned origin, not a one-off import

`SeedDataService` writes rows once and forgets it did. A gemeente that
edits a shipped case type and then takes a dossiq upgrade cannot be told
what moved, because nothing records that the row came from us.

So every shipped object carries the name and the version of the set it
came from. Adoption is a read of that origin, not a diff of the whole
register. An administrator sees three answers per object: shipped and
untouched, shipped and changed here, or ours.

OpenProject proves the shape at the container level: seventeen dependent
copy services behind one Copy project act
(`_round4/discovery/candidates.json`, C-configuration-24,
`configuration.tsv:34`). The lesson taken here is that copying is a
declared list of what comes along, never a deep clone of whatever is
reachable.

## D-2. Handling switches live on the case type and are read from one place

C-configuration-3 is a matrix hole because the behaviour exists and is
scattered. The default group is read in the routing strategies, the
default handler in `CaseTypeReader`, the mails in
`TermijnNotificationService`, the intake screen in the manifest.

The switches move onto `caseType` as one declared block and every reader
reads that block. Nothing else changes behaviour. A switch with no reader
is a lie, so publication refuses a switch nothing reads.

## D-3. Retired is a state, never a delete

`case-delete-guard` already refuses to delete a case type with cases.
Retirement is the act that guard leaves missing: the type stops accepting
new cases and keeps every existing one readable and finishable. Restoring
is the inverse and is recorded.

`caseType.isDraft`, `validFrom` and `validUntil` are already on the
schema. They are three fields answering a question nobody asks them
together, so the state is derived from them and named, rather than a
fourth field being added beside them.

## D-4. Copy is declared, at two levels, and never silently partial

One level is the case type, which `CaseTypeCopyService` already does. The
second is the domain: the case types, the role types, the templates and
the code lists that belong to one area of work.

A copy that could not carry something says so on the result. A copy that
silently drops a reference is the failure mode this design exists to
avoid, and it is the same shape as a seed that half ran.

## D-5. A template is a flag on the thing itself, not a second store

Taiga posts a project from `project-templates`
(`configuration.tsv:52`); Huly's issue templates sit beside issues
(`intake.tsv:47`). Both keep the template in the same store as the thing.

So a case type marked as a template is a case type, and a case template
is a case row that is never worked. This keeps one editor, one permission
model and one export. A template is excluded from every working list by
the same mechanism `lifecycle-acts-on-the-case` declares for a status
that is not visible by default.

## D-6. The template library grows kinds, not a second library

GLPI carries four template dropdowns beside the document ones
(`configuration.tsv:98`). `TemplateLibraryService` already exists for
documents and `EmailTemplateService` for mail. Adding a task, a note, an
approval and a result template means one `kind` on the existing record,
not four services.

A reusable process step is the same idea at the workflow level: a step
saved once and referenced from several case types, so a change to it
reaches all of them. It is a reference, not a copy, which is what makes
it different from D-4.

## D-7. A connection test probes and reports, and it never guesses

Redmine posts to `admin/test_email` and gets `test_connection`
(`cross-area.tsv:5`). The point is a live probe, not a config check.

Every connection screen gets one button that makes a real call, times
out, and prints what came back. A green that was never probed is worse
than no button, so the result states when it was measured and a test that
did not run reads as not tested rather than as passing.

## D-8. The shipped role set is adopted, not imposed

Twenty-one roles that appear on upgrade in an instance that already has
its own is a support call. So the set ships dormant and an administrator
adopts it in one act that can be undone while nothing has been granted
against it.
