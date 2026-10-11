# Tasks: tenancy-onto-openregister-organisation

Written 2026-09-10. The change had no tasks file, so its progress could only
be read out of the proposal's prose and `openspec status` had nothing to
report. These are the proposal's own five steps, one checkbox each, with the
current state measured against `development` rather than inferred.

**`openspec validate --strict` used to fail on this change, on purpose.** It
carried no delta specs, and it was not to get one until step 2 was done and
step 4 was ruled. Both conditions were met on 2026-09-11 (2a to 2h). The
2026-10-08 amend adds the delta in `specs/tenant-organisation-boundary/`,
written over the decided mapping only. It is not a placeholder: every
requirement in it has a task below that builds it.

Build rules: `openspec/woo-build-rules.md`.

## The five steps

- [x] 1 **Pin.** All five tenant middlewares now have tests:
  `TenantContextMiddlewareTest.php`, `TenantClaimValidationMiddlewareTest.php`,
  `QuotaEnforcementMiddlewareTest.php`, `MandateValidationMiddlewareTest.php`
  and `TenantIsolationMiddlewareTest.php` are all in `tests/Unit/Middleware/`.
  The three the proposal named as untested are the first three of those.
- [x] 2 **Map.** Drafted 2026-09-10, see the section at the end of this
      file. Four decisions came out of it (2a-2d) and they block step 4.
      For each of the seven schemas, what OpenRegister's
  `Organisation` already covers and what it does not. Not started. This is
  analysis and it destroys nothing, so it is the step to take next.
  `tenantMandate` and `tenantOnboardingTask` are the two the proposal expects
  to have no counterpart.
- [x] 3 **Decide the fail-open.** The session leads; neither the header nor
  the claim binds the tenant. Recorded in the proposal under "Decided: the PHP
  session is the source of truth for tenant".
- [~] 4 **Move.** Repoint the five middlewares, migrate the data, retire the
  schemas. **THE REVERSIBLE HALF IS DONE, 2026-09-11** (dossiq#2523). What
  remains is listed under "What step 4 left for step 5"
  at the end of this file.

  Nothing blocks it any more. The irreversible act this note used to name,
  the three field drops, turned out to be done already (see 2a), and
  everything else that held it has since cleared:

  - **2e and 2f, decided 2026-09-11.** Neither needs anything upstream:
    `retained` and `retainedAt` are already on `TenantLifecycleService` with
    `PURGEABLE_STATUS` pinned to `archived`, and `legalName` landed in
    openregister#3603 beside the `kvk` column that was always there.
  - **2g, fixed and merged.** dossiq#2436 repaired all four lookups together,
    `listTenantsForUser()`, `resolveUserRole()` and `loadActiveMatrix()` in
    `TenantAuthenticationService` plus `getQuota()` in `TenantQuotaService`.
    It was held on purpose for a while, because it makes a layer step 4
    retires actually enforce, and Ruben ruled on 2026-09-11 to merge it and
    then start step 4. Do not confuse it with dossiq#2449, which fixed the
    same reading defect in five other services and never gated this step.
  - The pins in 2h are what shows the re-pointed filters still scope.

  **Decided 2026-09-11 by Ruben, and it belongs to this step: dossiq stops
  counting request quota, and OpenRegister alone enforces it.** 2b found that
  one shared limit was being counted twice, by OpenRegister's
  `TenantQuotaMiddleware` on its own routes and by dossiq's
  `QuotaEnforcementMiddleware` on dossiq's, so a tenant could spend the full
  limit on each. Measured: OpenRegister registers its middleware through
  `$context->registerMiddleware()` with no `global` flag, so it guards only
  OpenRegister's own controllers.

  So step 4 retires dossiq's `QuotaEnforcementMiddleware` rather than teaching
  it to share a counter. **State the consequence plainly rather than leaving
  it to be discovered: dossiq's own routes then carry no request quota at
  all.** The alternative considered and rejected was making OpenRegister's
  middleware global, which would have closed that gap and started counting
  every app's routes fleet-wide. If the unguarded routes later matter, that is
  the change to make, and it belongs upstream in OpenRegister rather than in a
  second dossiq middleware.
- [x] 5 (the `Tenants` and `TenantDetail` pages, the `TenantsMenu` entry, the tenant deep link and both menu layouts' waivers are gone; `NoRetiredTenantStoreTest::testNoManifestPageOrMenuNamesTheTenantsRoute`. The DqTenant board draws an organisation page over the Organisation and its satellites: that is a new page, owed as a follow-up, not these schema-bound ones) **Remove the surface.** The `Tenants` and `TenantDetail` pages are
  both still in `src/manifest.json`. They go once the store they administer is
  gone, not before.
  Runs after task 6.9. Remove `Tenants`, `TenantDetail` and the `TenantsMenu`
  entry together (REQ-TOO-004).
  - **fails today**: `tests/Unit/Architecture/NoRetiredTenantStoreTest.php`
    `testNoManifestPageOrMenuNamesTheTenantsRoute`.

## Measured 2026-09-10

All seven tenant schemas are still declared: `tenant`, `tenantConfiguration`,
`tenantQuota`, `tenantUser`, `tenantMandate`, `tenantBillingEvent` and
`tenantOnboardingTask`. Five middlewares still resolve dossiq's own tenant
(`TenantMiddleware`, `TenantContextMiddleware`,
`TenantClaimValidationMiddleware`, `TenantIsolationMiddleware`, plus the
quota and mandate pair registered beside them). Nothing has moved onto
`Organisation`.

## Step 2, the map, drafted 2026-09-10

Task 2 asked "for each of the seven schemas, what OpenRegister's Organisation
carries". Here it is, read off `openregister/lib/Db/Organisation.php` (44
columns) against each schema's properties.

**`tenant` maps almost completely, once renames are allowed.** A name-level
comparison scores it 2 of 9, which is why this needed doing by hand:

| tenant | Organisation | Note |
|---|---|---|
| `slug` | `slug` | 1:1 |
| `status` | `status` | 1:1, but the value sets need comparing |
| `displayName` | `name` | rename |
| `legalName` | `description` or `name` | **decision needed** |
| `kvkNumber` | `kvk` | rename. `Organisation` also has `rsin`, `oin` and `tooi`, which dossiq lacks |
| `createdAt` | `created` | rename |
| `activatedAt` | `provisionedAt` | rename, and the semantics match |
| `terminatedAt` | `deprovisionedAt` | rename, and `mergedAt` / `suspendedAt` are extra |
| `tier` | — | **no column. Decision needed** |

**The six satellites do NOT map, and that is the real finding.**

| Schema | Organisation offers | Verdict |
|---|---|---|
| `tenantUser` (7 props) | `users`, `groups`, `owner` as JSON lists | Membership survives. `role`, `mfaEnabled`, `eherkenningLevel`, `joinedAt`, `lastActiveAt` have **no home** |
| `tenantQuota` (7 props) | `storageQuota`, `bandwidthQuota`, `requestQuota` as typed columns | A generic `quotaType` row does not fit three fixed columns. `currentUsage`, `resetAt`, `softLimitWarningPercent`, `enforcement` have **no home** |
| `tenantConfiguration` (8) | — | **Nothing.** branding, domain, locale, timezone, dateFormat, currency, features |
| `tenantMandate` (6) | `authorization` (json) | Possibly, but mandate matrices and signed documents are not an authorization rule set |
| `tenantBillingEvent` (7) | — | **Nothing.** OpenRegister has `TenantUsage`, which is metering, not billing events |
| `tenantOnboardingTask` (6) | — | **Nothing here, but see below** |

**`tenantOnboardingTask` belongs to the task cluster, not this one.** It is a
step, a completedBy, a completedAt and a blockedReason: that is a task, and
OpenRegister's `Task` carries all four (`state`, `completed_by`,
`completed_at`, `blocked_reason`). Moving it here would mean inventing a home
on `Organisation` for something the fleet-generic task already models. It
should be re-filed under the caseTask migration.

## What this step establishes

The proposal's framing was "dossiq runs a second, parallel tenancy model
beside the one OpenRegister already owns". That is true of `tenant` itself
and **not** true of five of its six satellites: OpenRegister has no
configuration store, no billing events, no per-membership role or assurance
level, and no generic quota rows.

So this is not one migration. It is:

- **`tenant` onto `Organisation`** — a real, mostly mechanical rename job.
- **`tenantOnboardingTask` onto `Task`** — re-filed to the task cluster.
- **Five satellites with nowhere to go** — either OpenRegister grows the
  fields, or they stay in dossiq as satellites of an `Organisation` reference
  instead of a `tenant` reference, or the capability is dropped.

The third of those is a product decision and blocks step 4 (Move). Step 3
(pinning tests) is already done and does not depend on it.

- [x] 2a Decided 2026-09-11 by Ruben: follow the measurement.
      - **Drop `contractRef`, `isolationMode`, `dataResidency`.** Already
        done, and this file did not know it: dossiq#1390 (merged
        2026-08-27) removed the declaration, the write and `TIER_ISOLATION`,
        with two mutation-checked tests that fail if a field comes back in
        the payload or the schema. The only residue was
        `docs/openapi/tenant-saas.yaml`, which still advertised all three; it
        stops doing so in this change. Stored data, checked 2026-09-11 on the
        one instance reachable from here (the shared dev instance): the
        `tenant` table holds 0 rows, live or soft-deleted, and has no column
        for any of the three; 0 objects in the blob store carry any of the
        three keys; the audit trail holds 0 entries for the `tenant` schema
        and 0 naming the keys (8 substring hits are shillinq's
        `contractReference`, a different field). The limit of that evidence:
        the instance was rebuilt on 2026-08-30, after #1390, so it never ran a
        schema that declared them, and it cannot speak for an install older
        than that. #1390 recorded that none is in production. The mock
        register never carried a tenant object with any of the three
        (`git log -S` over `lib/Settings/`).
      - **Move `kvkNumber` and `legalName` onto `Organisation`.** Only one
        of the two needed an upstream change: `Organisation` already has
        `kvk`, so `kvkNumber` is a rename. `legalName` is added by
        openregister#3603 (`legal_name`, nullable, no default and no
        fallback to `name`, since a copied name would read as a verified
        legal name). **#3603 merged 2026-09-11**, so both halves are now on
        `Organisation` and this sub-decision needs nothing further upstream.
      - **Keep `tier` in dossiq.** Once `tenant` retires, its home is
        `tenantConfiguration`, which is already dossiq's one-row-per-tenant
        settings store. Its quota role moves to `Organisation`: the
        `storage_gb` default is written to `storageQuota` (GB times 1024^3,
        the column is bytes) and `api_calls_per_hour` to `requestQuota`.
        OpenRegister enforces `requestQuota` in hourly buckets, although the
        entity docblock calls it "per day"; the enforcement is what counts.
        Its remaining job, choosing which zaaktype templates to seed, stays a
        dossiq concept.
- [x] 2b Decided 2026-09-11 by Ruben: `tenantConfiguration`,
      `tenantBillingEvent`, `tenantUser`, `tenantMandate` and `tenantQuota`
      stay in dossiq and reference `Organisation` instead of `tenant`.
      OpenRegister does not grow billing or configuration stores.
      - **How they re-point.** Keep the property name `tenantRef` and change
        its `$ref` from `tenant` to `nc-organisation`, OpenRegister's
        projection of the entity (openregister#3363). The name stays because
        `tenantRef` is the filter key in every scoping query this subsystem
        makes (`listTenantsForUser`, `resolveUserRole`, `loadActiveMatrix`,
        `getQuota`, `validateGoLive` and the billing and configuration
        reads). A rename that misses one call site does not fail loudly.
        Measured on the dev instance: a filter on a property the schema lacks
        returns no rows. That fails closed for membership, role and mandate
        (nothing resolves), and open for quota, where no row means "allow".
        Keeping the key is what keeps the move to a change of target.
      - **Stored values.** `TenantMigrationService` preserves the tenant's
        UUID onto the Organisation it creates, so a `tenantRef` keeps
        resolving without a rewrite. Except in one case, and it is an
        isolation hazard: the migration is idempotent by SLUG, so a tenant
        whose slug an existing Organisation already holds is skipped and
        reported against that other Organisation's UUID. Rewriting
        `tenantRef` from that report would attach the tenant's users,
        mandates and quotas to a different organisation. Step 4 keys the
        migration by UUID and refuses a slug collision instead, as the
        partner migration already does (dossiq#1613).
      - **`tenantQuota` against the three quota columns.** Of the four
        `quotaType` values, `storage_gb` maps to `storageQuota` and
        `api_calls_per_hour` to `requestQuota`; `cases_per_month` and
        `active_users` have no column and stay rows as they are.
        `bandwidthQuota` has no dossiq counterpart and stays OpenRegister's
        alone. For the two that map, the LIMIT lives on `Organisation` only:
        the row keeps what the entity has no home for (`currentUsage`,
        `resetAt`, `softLimitWarningPercent`, `enforcement`) and stops
        carrying `limit`, and `TenantQuotaService::getQuota()` reads the limit
        from the Organisation. One limit, no second copy to drift. One
        consequence to settle inside step 4: OpenRegister's own
        `TenantQuotaMiddleware` counts `requestQuota` on OpenRegister's
        routes and dossiq's `QuotaEnforcementMiddleware` counts
        `api_calls_per_hour` on dossiq's, so one shared limit counted twice
        lets a tenant make the full limit on each.
      - **Found while checking stored data.** The dev instance holds 12
        `tenantQuota` rows and 7 `tenantOnboardingTask` rows whose
        `tenantRef` is `00000000-0000-0000-0000-000000000000`, `...0001`,
        `...0002` or `...000d`, while the `tenant` table is empty: test
        fixture ids written into the shared instance on 2026-08-30. Harmless
        there, but the step 4 migration must treat a `tenantRef` that
        resolves to nothing as an orphan to report, never as something to map.
- [x] 2c Re-filed 2026-09-11. `openspec/changes/remove-casetask/tasks.md`
      carries `tenantOnboardingTask` as follow-up 7.1, with its field
      mapping onto the engine `Task`. Not migrated here, because it is not
      trivial: `TenantOnboardingService::activate()` is gated on
      `validateGoLive()` and ends by flipping the tenant's status to
      `active`, which is exactly the part 2d leaves undecided.
- [x] 2d Compared 2026-09-11. **They do not map 1:1, and that blocks step 4.**

      | `tenant` status | may go to | `Organisation` status | may go to |
      |---|---|---|---|
      | `onboarding` | `active` | `provisioning` | `active` |
      | `active` | `suspended`, `terminated` | `active` | `suspended`, `deprovisioning` |
      | `suspended` | `active`, `terminated` | `suspended` | `active`, `deprovisioning` |
      | `terminated` | nothing | `deprovisioning` | `archived` |
      | | | `archived` | nothing |

      Read from `TenantSaasService::LIFECYCLE_TRANSITIONS` and
      openregister's `TenantLifecycleService::STATE_TRANSITIONS`. Four states
      against five. Three pair up by shape. `terminated` has no single
      counterpart, and both candidates carry behaviour dossiq does not have:

      - `deprovisioning` is transient. `TenantDeprovisionJob` runs hourly and
        moves every local tenant in it to `archived`.
      - `archived` is purged. `TenantPurgeJob` permanently deletes an
        archived Organisation once its `deprovisionedAt` is older than
        `tenantRetentionDays`, default 90. Dossiq's termination is
        non-destructive by design (`archiveAndDelete()` was removed for being
        an irreversible whole-tenant delete), and its retention parameter
        defaults to one year and is only logged.

      So either mapping enrols a terminated tenant in a 90-day hard delete
      that dossiq deliberately does not have. Mapping `terminatedAt` onto
      `deprovisionedAt`, as the step 2 map above proposes, would make every
      tenant terminated more than 90 days ago purgeable on the job's first
      run after the migration.

      The pair that matches by shape still differs in who may act. When a
      user's active Organisation is `provisioning`, openregister's
      `TenantQuotaMiddleware` answers 403 to every request that user makes on
      openregister's routes unless they are an instance admin, and dossiq's
      frontend reads and writes through those routes. A dossiq
      tenant in `onboarding` is one whose tenant admin is still working
      through the onboarding steps.

      A mapping was already invented once. `TenantMigrationService::STATUS_MAP`
      (from `migrate-tenant-to-or-tenant`, June) sends `onboarding` to
      `provisioning` and `terminated` to `archived`. It sets no
      `deprovisionedAt`, so the purge job skips what it writes today, but
      `archived` is terminal in openregister and a live termination cannot
      reach it without passing `deprovisioning`. Recorded here and left
      alone: changing it would be choosing a mapping.
- [x] 2e **Decided 2026-09-11: option (c).** OpenRegister grows a terminal
      state that is retained rather than purged, and `terminated` maps to it.
      Options (a) and (b) were both rejected for the same reason: each makes
      dossiq's retention a property of how dossiq happens to write the row.
      (a) reaches `archived` while leaving `deprovisionedAt` null, so the
      purge skips it only for as long as nobody sets that field, and the
      protection is an omission rather than a rule. (b) accepts the purge and
      then tries to outrun it by setting `tenantRetentionDays`, which makes
      every consumer of the platform responsible for knowing dossiq's
      retention period. A retained terminal state says the thing once, in the
      lifecycle, where every app reads it.

      **OpenRegister already implements it.** Checked against
      `openregister/development` on 2026-09-11, not against the local
      checkout, which sits on an older branch and still shows the earlier
      lifecycle. `TenantLifecycleService::STATUS_RETAINED` exists, `retain()`
      stamps `retainedAt` and leaves `deprovisionedAt` untouched, and
      `PURGEABLE_STATUS` is pinned to `archived` so `TenantPurgeJob` selects
      on it and re-checks every row before deleting. Retained is entered from
      `active` or `suspended`, the same two states `deprovisioning` is entered
      from, so ending a tenancy is a choice between deleting and keeping. The
      one way out is `deprovisioning`, taken deliberately once the retention
      period is over.

      So this needs nothing upstream. `terminated` maps to `retained`, and
      dossiq's retention period stops being dossiq's to enforce. Unblocks
      step 4.

      **What the migration actually does with it** (dossiq#2523): maps
      `terminated` to `retained`, stamps `retainedAt` from the tenant's own
      `terminatedAt` rather than from today, so an old retention period is not
      silently restarted, and writes no `deprovisionedAt`, which is the column
      the purge measures its window from.
- [x] 2f **Decided 2026-09-11: the Organisation is `active` during
      onboarding.** dossiq keeps its own onboarding state beside it rather
      than holding the Organisation in `provisioning`.

      The 403 above is the reason. `provisioning` is not a state a tenant can
      transact from, so an Organisation held there cannot carry the very
      writes onboarding consists of, and onboarding would have to be
      performed by something outside the tenant it is onboarding. Making the
      Organisation `active` from the start keeps the platform's question
      ("may this organisation act?") separate from dossiq's question ("has
      this customer finished setting up?"), which is the split the whole
      change is built on. Unblocks step 4.
- [x] 2g Found while mutation-checking, 2026-09-11: **the tenant lookups
      read OpenRegister rows in a shape OpenRegister does not return.**
      `ObjectService::findAll()` returns `ObjectEntity` objects (measured on
      the dev instance, 4 of 4 rows). `listTenantsForUser()` keeps only
      arrays, so on a real install no user has a membership, the session
      never resolves a tenant, and all five middlewares see an unbound
      request and step aside. `resolveUserRole()` and `loadActiveMatrix()`
      index the same objects as arrays, throw, and deny;
      `TenantQuotaService::getQuota()` returns one from an `?array` method,
      the `TypeError` is caught, and every quota allows. The unit tests never
      saw it because none fed the lookups a row in the real shape. It is
      pinned as-is in `TenantScopedLookupsTest` and not fixed here: fixing
      the membership lookup alone would bind tenants and then deny every
      write by every member through the other two. The three move together,
      in step 4.
      **Fixed 2026-09-11, ahead of step 4, in one change.** Membership, role,
      mandate matrix and quota now read each row through
      `OpenRegisterRowNormaliser`, the helper `AwbProceedingScanner` already
      used for the same return shape. `ResetMonthlyQuotasJob` moved with them:
      it indexed the same entities outside any catch, so it died on every run,
      and a quota that enforces but never resets would have kept a `block`
      quota refusing past its window. The pinning test
      `testAMembershipRowInTheShapeOpenRegisterReturnsIsDropped` turned red as
      expected and now asserts the row resolves; entity-shaped tests pin the
      other three lookups, a member allowed exactly what the matrix grants,
      a tenant over quota refused with 429, and the job resetting in place.
      The test stub's `getObject()` now puts the uuid in front as `id`, as the
      real class does. Found on the way and not fixed here: the same defect
      in `TenantOnboardingService` (`getProgress()`, `markStepComplete()`),
      `TenantConfigurationService::getConfig()`, `TenantBillingService`'s
      monthly listing, `BezwaarDecisionListener::containsDecidedDecision()`
      and `BerichtenboxReadStatusJob`.
- [x] 2h Mutation survey of the scoping checks step 4 re-points, run
      2026-09-11 against the whole tenancy suite. Sixteen mutations, each
      disabling one comparison or filter. Before this change 7 of the 16
      left the suite green: the `userRef` filter on memberships, the
      `tenantRef` filter on role, mandate and quota lookups, a denied mandate
      decision, the mandate matrix asked about another tenant, and
      `isMemberOf()` answering true. `TenantScopedLookupsTest` and three new
      `MandateValidationMiddlewareTest` cases close all seven; all 16 now
      redden a named test, and so does fixing the pinned 2g defect.

## What step 4 did, 2026-09-11

The reversible half, in six commits on `step4/tenancy-onto-organisation`
(PR #2523). Each commit records the mutation it ran and the assertion that
reddened, and every scoping filter this step re-points now has one.

1. The five satellites keep the property name `tenantRef` and reference
   `nc-organisation`. Both register descriptors.
2. `TenantOrganisationResolver` resolves the tenant as an Organisation and
   falls back to the legacy `tenant` row, in that order.
   `TenantContextMiddleware` is the chain's only resolution point and now
   calls it.
3. `QuotaEnforcementMiddleware` retired.
4. The migration is keyed by uuid and refuses a slug collision.
5. `TenantMigrationService::reportOrphans()` reports satellite rows whose
   `tenantRef` resolves to nothing, and never maps one.
6. `storage_gb` and `api_calls_per_hour` read their limit from the
   Organisation, through `OrganisationQuotaLimits`.

### Three things this file said that turned out not to hold

- **2b calls the 12 zero-ish `tenantQuota` rows "test fixture ids written
  into the shared instance on 2026-08-30".** They are not fixtures. They are
  the shipped tier quota templates in `lib/Settings/dossiq_register.json`:
  three tiers, four quota types each, `tenantRef` `...0000`, `...0001` and
  `...0002`, one sentinel per tier. Every install has them. The orphan report
  counts them apart as `templates` rather than opening with twelve false
  alarms. The 7 `tenantOnboardingTask` rows at `...000d` are likewise the
  shipped default onboarding template.
- **A SEVENTH schema references `tenant`, and no part of this change names
  it:** `automaticAction.tenantId`, in both register descriptors. It is not a
  satellite and it was left alone, but retiring the `tenant` schema in step 5
  breaks its `$ref` as surely as it breaks `tenantOnboardingTask`'s.
- **Retiring `QuotaEnforcementMiddleware` stops more than the request
  quota.** It was the ONLY caller of `TenantQuotaService::consume()`, so
  `cases_per_month` is no longer counted either, although 2b keeps it as a
  row. `active_users` never had a counting path at all. The service keeps
  `consume()`, `decide()` and `setLimit()`.

## Merged with dossiq#2462, 2026-09-12

Another session implemented decision 2e in parallel, as "a terminated tenant
is retained, not enrolled in a purge". Both landed on the same `STATUS_MAP`.
Each carried something the other lacked, so the merge keeps both.

- **Theirs: `SUPERSEDED_STATUS_REPAIRS`.** Fixing the map only helps tenants
  migrated from now on. An instance that ran the June change already has
  terminated tenants in `archived` and onboarding tenants stuck in
  `provisioning`, and the idempotency guard skips exactly those rows on a
  re-run, so the run reports "skipped" over permanent damage. The repair fires
  only while the Organisation still carries the superseded value, so an
  operator's later deliberate move is never overwritten, and a second run
  finds nothing to do.
- **Mine: the uuid key and the refusal.**
- **Neither: what the two do together.** The repair now hangs off the
  uuid-found path, where the Organisation is provably this tenant. A slug
  collision is refused AND never repaired: there the uuid did not match, so
  the row belongs to somebody else and only shares a name, and repairing it
  would write a lifecycle status onto another organisation on the strength of
  a name. That is worse than the mis-report the refusal prevents, because it
  is a write.

The orphan scan moved to `SatelliteOrphanScanner` in the same change: the
union crossed phpmd's class-complexity ceiling, and scanning is a read-only
audit where migrating is a write.

## What step 4 left for step 5

Rewritten 2026-10-08 after Ruben's decision Q4: the tenant objects are not
deleted. They stay, read-only, as the anchor of the tenant audit trail, so
step 5 is no longer destructive. Nothing below deletes a tenant object.

- Keep the `tenant` schema declared, and describe it as the read-only audit
  anchor. Re-point the two properties that reference it,
  `tenantOnboardingTask.tenantRef` and `automaticAction.tenantId`, to
  `nc-organisation`.
- Remove the legacy fallback in `TenantOrganisationResolver::resolve()`, and
  `TenantSaasService`'s reads and writes of the `tenant` schema with it.
- Run the migration on upgrade for one release, through a repair step that
  reports how many tenants are left unmigrated (decision Q1). Stored `tenant`
  rows are never deleted.
- Remove the `Tenants` and `TenantDetail` pages from `src/manifest.json`.
- `TenantSaasService::LIFECYCLE_TRANSITIONS` goes with `TenantSaasService`.
  OpenRegister's `TenantLifecycleService::STATE_TRANSITIONS` governs the status.

## 6. Amend 2026-10-08: what gate 23 needs from this change

Read the amend section at the end of `proposal.md` first. It gives each of the
18 `Tenant*.php` files its owner. Ruben decided Q1 to Q4 on 2026-10-08. Q2 and
Q3 are built in `tenancy-onto-openregister-organisation-active-organisation`,
not here. The onboarding `skipped` mapping (`remove-casetask` task 7.1) is
still open; do not build anything that depends on it.

Every task names the requirement it meets and the test that proves it. A test
marked **fails today** must be run on `origin/development` before the change
and seen red; note the failure line in the PR body.

### The migration, for one release

- [x] 6.1 Add `--dry-run` to `dossiq:migrate-tenants`
  (`lib/Command/MigrateTenantsCommand.php`). It reads every tenant and prints
  the same summary, mappings, collisions and orphan report as the real run,
  plus the `unmigrated` count of 6.2, and writes nothing: no
  `OrganisationMapper` insert or update, no `ObjectService` save (REQ-TOO-001).
  - **fails today**: `tests/Unit/Command/MigrateTenantsCommandTest.php`
    `testTheDryRunWritesNothing` and
    `testTheDryRunReportsTheCollisionsTheRealRunWouldRefuse`. Drive both
    through the command's `execute()`, with `TenantMigrationService` real and
    the two OpenRegister seams doubled after reading their real signatures.
- [x] 6.2 (dossiq#3486 built the step; the anchor clause in this PR: `anchorMemberOrganisations()`, pinned by `tests/Unit/Repair/MigrateTenantsToOrganisationsTest.php` `testAMemberOrganisationWithoutAnAnchorGetsOne` and `testAfterTheRealRunEverySatelliteRowResolves`) Add `lib/Repair/MigrateTenantsToOrganisations.php`, registered under
  `<post-migration>` in `appinfo/info.xml`. It runs
  `TenantMigrationService::migrate()` on every upgrade and reports
  `unmigrated`: the number of stored tenants with no Organisation of the same
  uuid, refused collisions included. The count goes to the repair output and to
  the log at warning level when it is above zero. `TenantMigrationService`
  computes it, so the command and the repair report the same number. A refused
  collision stays refused and is counted, never mapped. The same step then
  creates the audit anchor of 6.15 for every Organisation a `tenantUser` row
  points at that has none (decisions Q1 and Q6, REQ-TOO-001, REQ-TOO-006).
  - **fails today**: `tests/Unit/Repair/MigrateTenantsToOrganisationsTest.php`
    `testTheRepairMigratesAndReportsWhatIsLeft`,
    `testARefusedCollisionIsCountedAsUnmigrated` and
    `testASecondRunMigratesNothingAndReportsZero`, built on the real
    `TenantMigrationService`, and `testAMemberOrganisationWithoutAnAnchorGetsOne`.
  - Through the caller: `testTheMigrationStepIsRegistered`, reading
    `appinfo/info.xml`.
- [x] 6.3 (run on a throwaway Nextcloud 34 + Postgres instance with demo data and posted on https://github.com/ConductionNL/dossiq/issues/3466#issuecomment-6095883350: 3 tenants, would migrate 3, collisions 0, refused 0, failed 0; 15 satellite orphans, all pointing at the 3 tenants without an Organisation yet, which the real run resolves) Held for a person, and a build session stops here: run
  `occ dossiq:migrate-tenants --dry-run` on the dev instance, read the
  collision and orphan report, and paste the output into this change's issue
  (REQ-TOO-001). Evidence: the pasted output.
- [ ] 6.4 Held for the release after this one. Once a person records in the
  issue that the repair step reports `unmigrated = 0`, delete
  `TenantMigrationService`, `MigrateTenantsCommand`, the repair step of 6.2 and
  their tests, re-point `SatelliteOrphanScanner` and `PartnerMigrationService`,
  and remove the ADR-004 entry of 6.11 (decision Q1, REQ-TOO-001).
  - **fails before**: `tests/Unit/Architecture/NoRetiredTenantStoreTest.php`
    `testNoClassUnderLibNamesTenantMigrationService`.

### The schema, the resolver and the store

- [x] 6.5 (`tests/Unit/Settings/NoPropertyRefsTheTenantSchemaTest.php`, `AutomaticActionFlowMigratorTest::testAnActionWhoseTenantIdIsAnOrganisationUuidStillMigrates`, `MandateValidationMiddlewareTest::testAMandateDecisionForAMigratedTenantStillAnchorsToItsTenantObject`; register 0.20.22, tenant schema 1.1.0) Keep the `tenant` schema as the read-only audit anchor (decision Q4).
  Re-point `automaticAction.tenantId` and `tenantOnboardingTask.tenantRef` from
  `$ref: tenant` to `$ref: nc-organisation` in both
  `lib/Settings/dossiq_register.json` and `lib/Settings/dossiq_mock_register.json`,
  keeping their values (decision 2b). Describe the `tenant` schema as the
  read-only anchor of the tenant audit trail, and bump the register
  `info.version`. Reduce the schema's `required` to `slug` and `displayName`,
  so an anchor needs no `tier` or `status` (decision 2a keeps `tier` on
  `tenantConfiguration`). Once 6.9 is built the only write on the schema is the
  anchor creation of 6.15; nothing updates or deletes a tenant object
  (decisions Q4 and Q6, REQ-TOO-002).
  - **fails today**: `tests/Unit/Settings/NoPropertyRefsTheTenantSchemaTest.php`
    `testNoPropertyInEitherDescriptorRefsTheTenantSchema` (four hits today) and
    `testTheTenantSchemaIsDescribedAsTheReadOnlyAuditAnchor`.
  - Through a reader: `AutomaticActionFlowMigratorTest`
    `testAnActionWhoseTenantIdIsAnOrganisationUuidStillMigrates`.
  - Through the audit caller: `tests/Unit/Middleware/MandateValidationMiddlewareTest.php`
    `testAMandateDecisionForAMigratedTenantStillAnchorsToItsTenantObject`,
    built on the real `TenantAuditTrailService` with `TenantSaasService` absent
    from the container.
- [x] 6.6 (`TenantOrganisationResolverTest::testATenantIdWithNoOrganisationResolvesToNothing`, `OrganisationQuotaLimitsTest::testAQuotaForATenantWithNoOrganisationHasNoLimitFromTheLegacyStore`) Remove the legacy fallback in `TenantOrganisationResolver::resolve()`.
  A tenant id with no Organisation resolves to `null`, and `TenantSaasService`
  is no longer injected (REQ-TOO-003).
  - **fails today**: `tests/Unit/Service/TenantOrganisationResolverTest.php`
    `testATenantIdWithNoOrganisationResolvesToNothing` (it asserts the legacy
    reader is never asked).
  - Through the caller: `tests/Unit/Service/OrganisationQuotaLimitsTest.php`
    `testAQuotaForATenantWithNoOrganisationHasNoLimitFromTheLegacyStore`,
    built on the real resolver.
- [x] 6.7 (`Application::REGISTER_SLUG`; no shared register constant existed, so it was added beside `APP_ID`; `NoRetiredTenantStoreTest::testNoClassUnderLibNamesTenantSaasService`) Move the register slug constant off `TenantSaasService`. Its eight
  readers (`ResetMonthlyQuotasJob`, `LinkInFlightContractDecisionsRepair`,
  `LinkInFlightRemainingDecisionsRepair`, `TenantOnboardingService`,
  `TenantBillingService`, `TenantConfigurationService`, `TenantQuotaService`,
  `TenantAuthenticationService`) read it from the register constant the rest of
  the app already uses. Search for that constant before adding one (REQ-TOO-004).
  - **fails today**: `NoRetiredTenantStoreTest`
    `testNoClassUnderLibNamesTenantSaasService`. The existing suites of the
    eight classes stay green.
- [x] 6.8 (`TenantOnboardingServiceTest::testActivateWritesNoTenantStatus`, `TenantOnboardingControllerTest::testActivatingAfterGoLiveAnswersOkWithoutAStatusWrite`) `TenantOnboardingService::activate()` stops writing a tenant status.
  The Organisation is `active` from the start (decision 2f), and the completed
  onboarding steps are dossiq's onboarding state (REQ-TOO-004).
  - **fails today**: `tests/Unit/Service/TenantOnboardingServiceTest.php`
    `testActivateWritesNoTenantStatus`.
  - Through the caller: `TenantOnboardingController` `activate` route test
    `testActivatingAfterGoLiveAnswersOkWithoutAStatusWrite`.
- [x] 6.9 (`NoRetiredTenantStoreTest`; the status-change audit row and the unsettled billing count moved to `OrganisationStatusChangeListener` on OpenRegister's `OrganisationUpdatedEvent`, and the two billing routes to `TenantBillingController` at the same URLs, both with their own unit test) Retire `TenantSaasService`, `TenantSaasController`, the `tenantSaas#*`
  routes in `appinfo/routes.php`, their tests, and the `/api/saas/tenants` paths
  in `docs/openapi/tenant-saas.yaml`. Its `LIFECYCLE_TRANSITIONS` goes with it:
  OpenRegister's `TenantLifecycleService::STATE_TRANSITIONS` governs the
  status from here. With it goes the last code that writes a tenant object
  (decision Q4, REQ-TOO-002, REQ-TOO-004).
  - **fails today**: `NoRetiredTenantStoreTest`
    `testNoRouteNamesTheTenantSaasController` and
    `testOnlyTheAnchorCreationWritesATenantObjectAndNothingDeletesOne`. The hydra route-reachability gate
    must show no dangling route.
- [x] 6.10 (`tests/vitest/TenantOnboardingTab.spec.js` `lists organisations from openregister`) `src/views/settings/tabs/TenantOnboardingTab.vue` lists tenants from
  OpenRegister's `GET /apps/openregister/api/organisations` instead of
  `/apps/dossiq/api/saas/tenants`. Its onboarding calls stay until
  `remove-casetask` task 7.1 lands (REQ-TOO-004).
  - **fails today**: a vitest spec beside the existing ones,
    `TenantOnboardingTab.spec.js` `lists organisations from openregister`,
    asserting the URL the component calls.

### The audit anchor (decision Q6)

- [x] 6.15 (`TenantOnboardingControllerTest::testInitialisingOnboardingForANewOrganisationCreatesItsAnchor`, `::testASecondInitialiseCreatesNoSecondAnchor`, `tests/Unit/Service/TenantServiceTest.php`) Add `TenantService::ensureAuditAnchor(string $organisationUuid): bool`.
  It creates, once, a tenant object whose uuid is the Organisation's, with
  `slug`, `displayName` and `createdAt` from the Organisation, and answers true
  when the anchor exists afterwards. It never updates or deletes one.
  `TenantOnboardingService::createOnboarding()` calls it before it writes the
  onboarding steps, and refuses to write them when it answers false
  (REQ-TOO-006).
  - **fails today**, through the caller:
    `tests/Unit/Controller/TenantOnboardingControllerTest.php`
    `testInitialisingOnboardingForANewOrganisationCreatesItsAnchor` and
    `testASecondInitialiseCreatesNoSecondAnchor`, built on the real
    `TenantOnboardingService` and `TenantService`.
  - When `remove-casetask` task 7.1 moves onboarding onto the engine `Task`,
    this call moves with it. Say so in a comment at the call.
- [x] 6.16 (`MandateValidationMiddlewareTest::testAMandateDecisionForANewTenantAnchorsOnItsOnboardingAnchor`, `TenantAuditTrailServiceTest::testAnEntryForATenantWithNoAnchorIsNotPersistedAndLogged`; the middleware now hands each decision to the audit writer) `TenantAuditTrailService::emit()` anchors a new tenant's entries on
  the anchor of 6.15, and keeps its fail-closed path: no anchor means no row,
  an error log and `persisted: false` (REQ-TOO-006).
  - **fails today**, through the caller:
    `tests/Unit/Middleware/MandateValidationMiddlewareTest.php`
    `testAMandateDecisionForANewTenantAnchorsOnItsOnboardingAnchor`.
  - Stays green, and is added if missing:
    `tests/Unit/Service/TenantAuditTrailServiceTest.php`
    `testAnEntryForATenantWithNoAnchorIsNotPersistedAndLogged`.

### Close out

- [x] 6.11 (gate 23 on this branch: `TenantOrganisationResolver.php`, `TenantMigrationService.php` and `TenantBillingController.php` printed as suppressed for rule 4; the only finding left is the two onboarding files, which `remove-casetask` 7.1 owns) Update `openspec/architecture/adr-004-tenant-cluster-adr-022-exception.md`
  for this change: add `lib/Service/TenantOrganisationResolver.php` under
  "Already consuming OpenRegister" with `(gate 23 rules: 4)`; add
  `lib/Service/TenantMigrationService.php` with `(gate 23 rules: 4)` under a
  new section that gives its reason (it runs as a repair step for one release,
  decision Q1) and its sunset: the next dossiq release, when task 6.4 deletes
  it and this entry. The gate reads only a date and only the ADR-wide `Sunset:`
  line, so the file's own sunset is stated in words beside the path, and the
  ADR-wide 2027-03-31 stays the backstop (decision Q7); record decision Q4 (tenant objects are kept
  read-only as the audit anchor); drop `TenantSaasService` and
  `TenantSaasController`; rewrite "Status of the work" to what is merged
  (REQ-TOO-005).
  - Evidence: `run-hydra-gates.sh --base origin/development` output for gate 23,
    pasted in the PR body.
- [x] 6.12 Verification while building: `TMPDIR` set to a sibling directory
  beside the clone, never inside it. Run only the unit tests of touched
  classes with `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter '<Class>'`
  and judge by the `Tests:` line, because a green suite exits 1 without a
  coverage driver.
- [x] 6.13 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`,
  then `npm run lint`, `npm run format` and any other leg `code-quality.yml`
  requires (check `package.json`). Then
  `scripts/run-hydra-gates.sh --base origin/development` and count the gates
  that ran. The coverage guard needs tests for every added statement.
- [ ] 6.14 One PR, `--base development`. Merge development in, never rebase.
  No `Co-Authored-By` on any commit. Done means merged on `development` with
  CI green and task 6.3 ticked by a person. Task 6.4 is done in the release
  after.
