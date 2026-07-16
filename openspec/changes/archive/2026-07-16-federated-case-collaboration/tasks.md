# Tasks

- [x] task-1: federated-case-collaboration#REQ-001 — schema: `caseFederatedShare`, `caseFederatedActivity`, extend `casetransfer` with federation fields
- [x] task-2: federated-case-collaboration#REQ-001 — `SettingsService` config wiring for the two new schemas
- [x] task-3: federated-case-collaboration#REQ-001 — `CaseSharingService::createFederatedShare/revokeFederatedShare/getCaseIdForFederatedShare` + field/document allow-list enforcement + fail-closed OR-unavailable guard
- [x] task-4: federated-case-collaboration#REQ-003 — `CaseTransferService` federation extension: `remoteCloudId`, idempotency key, custody audit trail, `resolveFederatedTransferShare`, pre-existing `handleTransfer` authz gap fix
- [x] task-5: federated-case-collaboration#REQ-002 — `CaseCollaborationService` (NEW): local + remote activity post/list on a federated case share
- [x] task-6: federated-case-collaboration#REQ-001,002,003 — `CaseSharingController` endpoints (createFederatedShare, revokeFederatedShare, handleFederatedTransfer [PublicPage], postActivity, listActivity, postRemoteActivity/listRemoteActivity [PublicPage]) + routes.php
- [x] task-7: federated-case-collaboration#REQ-004 — orphan fix: wire `CaseSharingTab.vue` (NEW) into the case-detail sidebar; extend `ShareTab.vue`/`CreateShareDialog.vue`/`CaseTransferDialog.vue`; add `CreateFederatedShareDialog.vue`, `FederatedActivityPanel.vue` (NEW)
- [x] task-8: federated-case-collaboration#REQ-004 — `PublicFederatedTransferPage.vue` (NEW) for remote token-authenticated accept/reject; manifest.json public route
- [x] task-9: federated-case-collaboration#REQ-005 — PHPUnit: share scoping, authority model, transfer idempotency/audit, revocation, fail-closed, feature-gate
- [x] task-10: federated-case-collaboration#REQ-005 — Vitest: UI wiring helpers
- [x] task-11: docs — update `docs/Features/case-sharing-collaboration.md` "Planned Features" to reflect what shipped
- [x] task-12: l10n en/nl parity for all new `t('procest', ...)` strings
