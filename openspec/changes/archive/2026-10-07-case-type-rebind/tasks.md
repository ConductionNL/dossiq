# Tasks: case-type-rebind

Tier: V1. Kind: code. Row 2.13. Waits on openregister
`migrate-run-between-versions`.

- [x] 1.1 `lib/Service/CaseRebindService.php`: validate (D-1), migrate,
  write, re-arm (D-2), group check (D-3); typed refusals.
  - `tests/Unit/Service/CaseRebindServiceTest.php`: missing property
    refused, engine refusal stops all, handler refused, happy path
  - `@spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md`

  Eleven tests, all four of the named ones plus the draft target, a
  status belonging to another case type, an empty reason and an empty
  actor. The two refusal tests assert the EMPTY WRITE LOG rather than
  only the exception: a service that wrote the case and then threw is
  indistinguishable from this one if you only catch.

  🔑 THE MAPPING IS NOT DERIVABLE, AND THE FIXTURE SAYS SO OUT LOUD. Both
  seeded case types carry a status called "In behandeling", which is
  exactly the coincidence a rebind must not treat as a mapping: across
  two case types a name is what an author happened to write, not an
  identity. {@see CaseVersionMove} may map by name because two versions
  of one type are copies of each other; this may not, which is the whole
  reason it is a second class rather than a flag on the first.

- [x] 1.2 `TermijnService::rearmForDefinition()` with the fixture pair.

  `tests/Unit/Service/TermijnRearmTest.php`, five tests. The pair is
  asserted on BOTH end dates: a successor that took the target's 84 days
  and dropped the 14-day verdaging already granted passes any test that
  checks only one of them. Mutation-checked by forcing the carried days
  to zero, which reddened the `endDateCurrent` assertion alone, 18 of 19
  assertions still running.

  Two decisions worth reading. The successor is created BEFORE the old
  instance is closed, so a failure leaves the case with the clock it had
  rather than with none. And a target case type with NO term definition
  leaves the running term alone and says so in the answer, because
  completing a statutory term that has no successor is how a case
  silently stops being watched.

  `markTermijnCompleted()` took an optional `$rationale`, defaulting to
  the sentence it always wrote. A rebind recorded as "voltooid door
  beschikking" would put a decision in the trail of a case that never
  got one.

- [x] 1.3 Controller method and route, `#[NoAdminRequired]` with the group
  guard in the service (gate 12); ADR-105 translation.

  `lib/Controller/CaseRebindController.php`, three routes. The guard is
  in the service, and the controller adds the PER-CASE check on top:
  being a coordinator is not the same as being allowed near this case.

  The third route is `GET /api/rebind/permission` and carries no case
  id, which is not an oversight: see 2.2.

- [x] 2.1 `src/dialogs/CaseRebindDialog.vue`: target picker (versions from
  `case-type-version-chain`, other types), status mapping, missing
  properties, reason.

  The picker offers every published case type, versions of the case's own
  type included, labelled as versions so two rows reading
  "Omgevingsvergunning" are not picked blind. The dialog derives no
  verdict: `canRebind`, the missing names and every refusal sentence come
  from the server, so it cannot enable a button the write would refuse.
  Changing the target clears the status, because a status of the type you
  just moved away from is refused and being asked again beats being
  refused.

- [x] 2.2 `src/manifest.json` `#CaseDetail` header action
  `case-rebind`, group-gated.

  🔴 AN ACTION HAS NO GROUP VOCABULARY. `app-manifest-v2` types `action`
  with `additionalProperties: false` and the property list holds no
  `groups`, `roles` or `permission`; `check:manifest` refuses one
  outright. The gate is therefore `visibleWhen` in its `endpoint` form,
  which fetches a URL and compares one field, and the endpoint answers
  whether the CALLER is a coordinator. That shape has no case id, which
  suits the question: D-3 is about the group, and the per-case half is
  asked by the endpoints that write.

  A hidden button is not an authorization either way, which is why
  `CaseRebindService::rebind()` checks the same group itself.

- [x] 3.1 `tests/e2e/case-type-rebind.spec.ts`; `openspec validate
  case-type-rebind --strict`.

  Four scenarios. The refusal is probed as an authenticated account that
  is not in `dossiq-coordinators`, on an explicitly empty cookie jar,
  because Playwright fills a `newContext` call from the project's `use`
  block and the admin's captured session would otherwise ride along and
  answer as the admin. A superuser success would prove almost nothing.

  The seam the e2e exists for is `requiredAtStatus`: the unit tests read
  it out of a fake, and only a live register shows it survives the store
  as a value this app can read back.

## Open ask for openregister

`migrate-run-between-versions` (register row 3.16) is not shipped:
measured 2026-09-18 against `ConductionNL/openregister@development`,
`lib/Service/Flow` holds `FlowRunVersionPin` and `FlowVersionService` and
no migration class at all. `lib/Service/CaseType/EngineRunMigration.php`
is the seam, resolved by string like every other openregister seam here,
and it answers THREE states rather than two: asked and migrated, asked
and refused (which stops the whole rebind and carries the engine's own
sentence out), and not asked, which is today. Every answer says the run
stayed, in the same words `CaseVersionMove::RUN_NOT_MOVED` uses for the
same gap, so no answer pretends the run travelled.

The ask is one service, `FlowRunMigrationService`, with one method
`migrateRunForSubject(string $subjectId, string $targetDefinitionRef,
string $actorUid)`, answering `{migrated, reason}` and refusing rather
than throwing on a run it will not move. Both names are constants here,
and the method is checked with `method_exists` before it is called: a
class that exists with a different verb is a rename, and calling into it
blindly is how a rebind reports a migration that never happened.
