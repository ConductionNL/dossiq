# Tasks: deelzaken-inherit-the-parent-grants

Tier: V1. Kind: code. Row Q13.23. Waits on openregister
`rbac-inherits-to-children` (open on openregister `development`).

- [x] 1.1 `lib/Settings/dossiq_register.json`: declare `parentCase` as the
  hierarchy edge on `case`, with a depth cap; `relatedCases` is not
  declared (D-1).
  - `@spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md`
- [ ] 1.2 `lib/Service/CaseAccessGuard.php`: `hasCaseReadAccess()` asks the
  platform and the app-side resolution is deleted (D-2). **NOT DONE, and
  the tick this line used to carry was wrong.** dossiq#2921 shipped the
  consuming half and deliberately kept the walk; openregister#3873 has
  since landed and its PR body lists what dossiq may delete. It still
  cannot be deleted, and the reason is mechanical rather than a matter of
  taste:

  `MagicRbacHandler::applyRbacFilters()` says, in openregister's own
  comment, "If no authorization is configured, the schema is open to all",
  and `hasPermission()` consults `ObjectGrantResolver::isGranted()` — the
  funnel where #3873 expands inherited grants — ONLY inside the `private`
  scope branch. dossiq's `case` schema declares no `authorization` and no
  private scope, so at the platform level every authenticated user may read
  every case, and the hierarchy block this app ships is consulted on a path
  cases never take. Deleting the walk today would open every case to every
  account, and dossiq's unit tests stub `OCA\OpenRegister\*`, so it would
  do it green.

  The precondition is now pinned by
  `tests/Unit/Service/CaseReadGuardStaysUntilThePlatformHoldsItTest.php`,
  which fails the day the case schema becomes private-scoped or grows an
  authorization chain — at which point the deletion is the right move and
  the test says so in its failure message.
  - `tests/Unit/Service/CaseReadGuardStaysUntilThePlatformHoldsItTest.php`
- [x] 1.3 Pin the verb rule: a read grant on a parent does not become a
  mutation grant on a deelzaak (D-3).
- [x] 2.1 `src/manifest.json` `#CaseDetail`: a deelzaak opened through an
  inherited grant names the case that granted it (D-4).
  - `tests/vitest/caseAccessProvenance.spec.js`
- [x] 2.2 The Sharing tab of a case with deelzaken states that a share
  reaches them (D-5), in Dutch and English.
- [x] 3.1 `tests/e2e/deelzaken-inherit-the-parent-grants.spec.ts`;
  `openspec validate deelzaken-inherit-the-parent-grants --strict`.

## The key move openregister asked for, done

openregister#3873 reads both `parent` and `parentField` and says `parent`
is canonical and wins where both are present. The case schema now declares
both: `parent` so the edge is spelled the way the app that enforces it
spells it, and `parentField` beside it because an instance still running a
pre-#3873 openregister reads only that one, and dropping it would take the
edge away from exactly the instances with no inheritance to fall back on.

The other follow-up from that PR body, reading provenance off the share
listing's `inheritedFrom` instead of `readAccessSource()`, waits on the
same precondition: while the walk is what answers, it is also what the
Access tab must show.
