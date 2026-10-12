# Tasks: guards-refuse-when-unsure

Decision 224. One PR, `--base development`, stacked on the method-decomposition slices 8e to 8g.

## 1. One refusal for a check that could not run

- [x] 1.1 (`lib/Service/Zgw/ZgwRuleResults.php`) `notVerified()` answers 503 `check-not-completed` with the rule in the detail and logs the rule and the object at error (REQ-ZBR-020).
  - `tests/Unit/Service/Zgw/ZgwRuleResultsTest.php`: "a check that could not run refuses and is logged". Control: an ordinary refusal logs nothing.
- [x] 1.2 (`lib/Service/Zgw/ZgwRuleLookups.php`) `idsByField()` throws when the search cannot run. `findAllObjectsByField()` keeps its empty answer for the reference rules that are not part of this change.

## 2. The document rules

- [x] 2.1 (`lib/Service/Zgw/DrcObjectLinkRules.php`) drc-004 refuses when the counterpart lookup fails (REQ-ZBR-020).
- [x] 2.2 (`lib/Service/Zgw/DrcDocumentRelations.php`, `lib/Service/ZgwDrcRulesService.php`) drc-007 refuses when the relation search fails.
- [x] 2.3 (`lib/Service/Zgw/DrcDocumentRelations.php`) drc-003 refuses with the same answer and no longer claims a duplicate.
- [x] 2.4 (`lib/Controller/DrcController.php`) the relation check before a document delete (drc-008a) refuses when one of its three searches fails.
  - Each with a control in the same test: the same search answering lets the request through, or answers the rule's own refusal.

## 3. The case type delete check

- [x] 3.1 (`lib/Service/Zgw/ZtcPublishAndDeleteChecks.php`, `lib/Service/ZgwBusinessRulesService.php`) cases or final statuses that cannot be read block the delete as unverified; the ZGW delete answers 503 `destroy_check_not_completed` (REQ-ZBR-021).
  - `tests/Unit/Service/ZgwBusinessRulesServiceDestroyGuardTest.php` asserts it from the caller.

## 4. The debt list

- [x] 4.1 (`tests/Unit/Architecture/catch-return-null.allowlist.json`) two sites left, ceiling 263 to 261.

## 5. Live

- [ ] 5.1 (live pass, decision 139) With the store reachable: an objectinformatieobject create, a document delete and a case type delete answer as before.
