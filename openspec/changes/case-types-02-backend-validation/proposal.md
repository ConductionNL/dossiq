---
kind: code
depends_on: [case-types-01-seed-and-stores]
chain:
  - case-types-01-seed-and-stores
  - case-types-02-backend-validation
  - case-types-03-result-role-tabs
  - case-types-04-property-doc-decision-tabs
---

## Why

This is **member 2 of 4** in the `case-types` chain (decomposed from the oversized
`case-types` change per ADR-032). Predecessor: `case-types-01-seed-and-stores`
(declares the seed data + stores this member validates against). Successors:
`case-types-03-result-role-tabs`, `case-types-04-property-doc-decision-tabs`.

Backend publish validation is currently missing: the publish guard runs only in
the frontend, meaning the API can publish incomplete case types, bypassing
validation entirely. Likewise, a case type can be deleted while active (non-final)
cases still reference it, orphaning live cases. Both are server-side enforcement
gaps that the UI alone cannot close.

This member adds the server-authoritative guards: a publish-prerequisite check and
an active-case deletion guard, both in the existing `ZgwZtcRulesService`, plus the
unit tests that pin their behaviour. The member-01 seed data is deliberately built
to pass these guards (each seeded case type has ≥1 status type, ≥1 `isFinal`
status, and `validFrom` set).

## What Changes

- **REQ-CT-02b**: Backend publish validation — server-side enforcement of: at
  least one status type defined, at least one `isFinal` status, `validFrom` is set.
  Returns HTTP 422 with a structured `{ "errors": [...] }` body when the publish
  (`isDraft: true → false`) transition is attempted on an incomplete case type.
- **REQ-CT-01d**: Active case deletion blocking — prevent deletion when active
  (non-final) cases reference the type (HTTP 409); warn-and-confirm for closed-case
  references.
- **Unit tests** — pin `validatePublish()` and `validateDeletion()` behaviour
  (happy + error paths) under `composer check:strict`.

## Impact

- **Backend**: `lib/Service/ZgwZtcRulesService.php` — add `validatePublish()` and
  `validateDeletion()` methods and hook them into the case-type save and delete
  paths. No new service class (avoid service proliferation).
- **Tests**: `tests/Unit/Service/ZgwZtcRulesServiceTest.php` — ≥6 test methods.
- **No new schemas, no frontend changes** in this member.
