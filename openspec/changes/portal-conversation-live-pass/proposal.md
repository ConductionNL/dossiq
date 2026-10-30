# Portal conversation live pass

## Why

Row 6.7 (messaging with citizens through a portal) is still rated specified. Its change, `communication-portal-conversation-on-the-case`, was archived on 10 Oct with every build task done (inbox body and receipt date, read stamp, attachments, the resident's reply, the handler's dialog `src/dialogs/PortalMessageDialog.vue` behind the CaseDetail `message-applicant` action, the contact moment on the case) and two tasks owed: the live check (1.3) and the e2e journey (5.3), both needing a live instance with portaliq. Nothing else is missing, so the build plan is to run what is owed and close the row.

## What changes

- Run the live check and the written journey `tests/e2e/portal-conversation-on-the-case.spec.ts` against :8080 with portaliq.
- Fix what they find in follow-up PRs; then mark row 6.7 built.

## Impact

- No code unless the pass finds a defect. Spec: one requirement added to `portal-contribution` stating the round trip as a whole.
