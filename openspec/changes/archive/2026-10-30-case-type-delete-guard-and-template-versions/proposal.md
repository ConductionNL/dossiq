# Case type delete guard and email template versions on every write path

## Why

The case types list deletes through OpenRegister's generic object API, which
skipped the checks the admin editor made: a published case type, or one that
cases use, could be deleted, and its status types stayed behind. The case
type's Messages list edits templates through the same API, which skipped the
server-side versioning the email template endpoints keep.

## What changes

- `CaseTypeDeleteGuard` (ObjectDeletingEvent): refuses the delete of a
  published case type and of one that cases use, refuses when the cases
  could not be checked, and deletes the case type's status types when the
  delete may go ahead.
- `EmailTemplateVersionListener` (ObjectCreatingEvent, ObjectUpdatingEvent):
  a new template starts at version 1 and active; a change to name, subject or
  body keeps the earlier wording as an inactive copy and moves the template to
  the next version with `previousVersion` pointing at the copy. A deactivation
  makes no version, so the email template endpoints keep working as before.
