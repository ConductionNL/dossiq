# Tasks: portal-conversation-live-pass

The build tasks of `communication-portal-conversation-on-the-case` are done
(archived 10 Oct 2026). What is owed is the pass against a live instance with
portaliq, then the row update.

## 1. Live pass

- [ ] 1.1 On :8080 with portaliq enabled, send a message with one attachment
      from a case through the `message-applicant` action
      (`src/dialogs/PortalMessageDialog.vue`), open it as the resident in the
      portal inbox, reply, and check the read stamp and the contact moment on
      the case's contact tab (REQ-PORTAL-RT-01)
- [ ] 1.2 Run `tests/e2e/portal-conversation-on-the-case.spec.ts` against
      :8080 and record the exit code here
- [ ] 1.3 Each defect the pass finds gets its own fix PR with a test that
      fails before the fix; list the PR numbers here

## 2. Close the row

- [ ] 2.1 Set row 6.7 in `openspec/parity/capabilities.json` to built with
      the files the pass reached as evidence, and repoint portaliq rows
      `sib-dossiq-6-7` and `dem-tnd-portal-talk-on-case` in portaliq's
      `openspec/parity/capabilities.json` the same way
