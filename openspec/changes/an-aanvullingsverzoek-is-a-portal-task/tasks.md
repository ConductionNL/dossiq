# Tasks: an aanvullingsverzoek is a portal task

Filed and built 10 Oct 2026 by lane L2 (decision 169, Q-dossiq-L2-1).

## 1. Schema

- [x] 1.1 `portalTask` on `aanvullingsverzoek` (`lib/Settings/register.d/63-aanvullingsverzoek.json`, schema 1.3.0). Test: `AanvullingPortalTaskTest::testTheRequestSchemaTakesThePortalTask` (real merged register).

## 2. Raise and close

- [x] 2.1 `lib/Portal/AanvullingPortalTask.php`: `raise()` writes the external task (assignee `party:<portalSubject>`, case as subject, due at the end of the hersteltermijn day in Europe/Amsterdam, missing items in the description); `close()` terminates it as moot once the request leaves `open`. Neither throws. Test: `tests/Unit/Portal/AanvullingPortalTaskTest.php`.
- [x] 2.2 `AanvullingsverzoekService::ask()` raises the task after the request is written and remembers it on the request. Test: `AanvullingsverzoekServiceTest::testAskingRaisesThePortalTaskAndRemembersIt`.
- [x] 2.3 `AanvullingsverzoekService::write()` closes the task when a change moves the request out of `open`. Test: `AanvullingsverzoekServiceTest::testAWriteThatLeavesOpenClosesThePortalTask`.

## 3. Live

- [ ] 3.1 Live: a handler asks on a case of Sanne de Vries; her portal task list shows "Vul uw aanvraag aan" due on the hersteltermijn; recording the answer removes it. (live pass, decision 139)
