# Tasks: sensitive-fields-declared

Tier: V1. Kind: config. Row 5.6.

- [x] 1.1 Inventory (D-1); record the list here.

  Twenty properties across eight schemas. Found with
  `grep -n -i "bsn\|burgerservicenummer"` over `lib/Settings/` and by reading
  every schema that carries a `gdprClassification` block. The list is written
  out in `tests/Unit/Settings/SensitiveFieldsDeclaredTest.php::INVENTORY` as a
  literal rather than re-derived: a test that re-derives the list from the same
  files it checks passes on an empty list, and an empty list is exactly what a
  bad merge leaves behind.

  | schema | properties |
  |---|---|
  | `brpPerson` | `citizenServiceNumber` |
  | `wmoZaak` | `bsn`, `supportRequest`, `householdsComposition`, `needsAssessmentId` |
  | `jeugdwetZaak` | `jeugdigeBsn`, `supportRequest`, `gezinsplanId`, `supervisionOrderActive`, `mdoConsultationIds` |
  | `participatiewetZaak` | `bsn`, `incomeAssessment`, `equityAssessment`, `householdsSituation` |
  | `gezinsplan` | `gezinsleden` (each member carries a BSN) |
  | `indicatiestelling` | `advisedSupport`, `investigationMinutes` |
  | `mdoOverleg` | `gedeeldeGegevens`, `minutes` |
  | `toestemming` | `grantedByBsn` |

  **`gdprClassification` is deliberately NOT in the list**, though the proposal
  reads as if it would be. It is the block that says WHICH categories a case
  processes, not the special-category data itself, and it is REQUIRED on all
  three sociaal-domein schemas. Hiding a required field from the people who
  edit these cases risks a write that arrives without it. The fields the block
  is about are in the list instead, which is what the requirement asks for.

  Not listed either, and each for a reason: `caseType.personalDataCategories`
  and `complaint.complainant` matched the BSN grep on their DESCRIPTIONS only;
  `beschikking.addressee` names the addressee of a decision, which is the
  decision's own subject line; `avgIncident` and `reIntegratieTraject` hold no
  special-category value of their own.

- [x] 1.2 Field rules on every listed property; repair step creates the
  group.
  - `"authorization": {"read": [{"group": "dossiq-sensitive"}]}` on each of the
    twenty, in `register.d/25-brp-kvk.json`, `register.d/50-sociaal-domein.json`
    and `dossiq_mock_register.json`, which mirrors the same schemas. The shape
    is the one `register.d/38-markers-and-assessments.json` already ships for
    `riskAssessment`, so it is a vocabulary this OpenRegister demonstrably
    reads rather than one this change invented.
  - READ ONLY, no `update` rule. The requirement asks for readable by the group
    only. An update rule would also refuse the BRP sync and every importer that
    writes a BSN, which is not what row 5.6 is about.
  - THE GROUP IS PROVISIONED AND EMPTY. `dossiq-sensitive` is added to
    `ProvisionAssignedGroups::ASSIGNED_GROUPS`. A group that does not exist and
    a group with no members are indistinguishable to `isInGroup()`, so a rule
    naming an unprovisioned group is a permanent denial an administrator cannot
    lift from the Nextcloud UI. Empty is the right starting state: sensitive
    data should be readable by the people an administrator names.
  - EVERY GUARDED SCHEMA MOVED ITS VERSION (`brpPerson` 1.1.0 to 1.2.0, the
    seven others 1.0.0 to 1.1.0), in both register files. OpenRegister
    FAST-SKIPS a schema whose version it already holds, so without this the
    twenty rules would have been committed, reviewed and never imported while
    every gate stayed green. The unit test asserts the versions for that reason
    and not as a nicety.
  - `@spec openspec/changes/sensitive-fields-declared/specs/security-hardening/spec.md`

- [x] 1.3 `lib/Service/CitizenLookupGuard.php`: drop the field check, keep
  the rate limit; unit test updated.

  **MEASURED 2026-09-18: THE CLASS HAS NEITHER.** It is 143 lines with one
  public method, `isCitizenLookupAllowed(IUser)`, which asks whether the caller
  may resolve a citizen identifier AT ALL. There is no field-level branch to
  drop and no rate limit to keep; the task's description of the class does not
  match the class.

  So the code is UNCHANGED, on purpose. This guard is the endpoint fix for
  PROC-IDOR-01: before it existed, `GET /api/kcc/voorblad?burgerId=…` answered
  200 with a citizen's open cases, the caller's phone number and the free-text
  summary of every previous call, to any authenticated account. Deleting it
  because this change "retires the guard" would have reopened that, and the
  field rules above do not cover it: they hide a property on an object, while
  this refuses an endpoint that takes a raw identifier and walks a population.

  What the change does add is the assertion the spec's third scenario names:
  `SensitiveFieldsDeclaredTest::testTheGuardDoesNotDecideAboutAField` lists the
  class's methods and fails when a field-level branch appears.

- [x] 1.4 `sociaalDomeinAuditLog`: stop writing reveal rows; keep the rest.

  **MEASURED 2026-09-18: NOTHING WRITES A ROW.** `git grep sociaalDomeinAuditLog`
  finds the schema in the two register files, the fragment unit test, and two
  notes in `src/data/capabilityComparison.json` that already say the schema has
  zero readers. There is no PHP, JS or Vue writer anywhere in the app, so there
  are no reveal rows to retire. The schema is left exactly as it is: it is a
  declared shape another change may fill, and deleting it would be a scope this
  change did not ask for.

  The consequence is worth stating plainly rather than ticking: the reveal is
  accountable only where OpenRegister's own field-access audit records it. On an
  OpenRegister without that audit, a member of `dossiq-sensitive` reads a BSN
  and nothing anywhere records that they did. The e2e below asks the instance
  which of the two it is instead of assuming.

- [x] 2.1 `tests/e2e/sensitive-fields.spec.ts`; `openspec validate
  sensitive-fields-declared --strict`.
  - TWO ACCOUNTS, TWO GROUPS, ONE OBJECT. Reading as an administrator proves
    nothing: OpenRegister's permission handler returns true for the admin group
    before it looks at a field, so an admin read is green whether the rule
    landed or not. The control read comes first, because an absence on its own
    is also what an empty fixture gives.
  - A THIRD TEST FOR THE REQUIRED-FIELD HAZARD, which this change could not
    settle without an instance. The BSN is REQUIRED on `brpPerson` and on the
    three sociaal-domein schemas, and a reader outside the group does not
    receive it, so whatever they save cannot carry it. Three outcomes are
    possible: the write is merged, refused for a missing required field, or
    accepted with the BSN wiped. The third is a silent data loss this change
    would have caused. The test asserts what the MEMBER reads afterwards,
    because that is the only reading that tells the three apart, and it logs
    the write's status so the run says which one happened.
  - NOT RUN: there is no Playwright runner on the build host. Written and
    tagged for the nightly, like the sibling `field-rules.spec.ts` it is
    modelled on.
  - `npx openspec validate sensitive-fields-declared --strict`: valid.
