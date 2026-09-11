# Tasks: tenancy-onto-openregister-organisation

Written 2026-09-10. The change had no tasks file, so its progress could only
be read out of the proposal's prose and `openspec status` had nothing to
report. These are the proposal's own five steps, one checkbox each, with the
current state measured against `development` rather than inferred.

**`openspec validate --strict` still fails on this change, on purpose.** It
carries no delta specs, and it should not get one until step 2 is done and
step 4 is ruled: a delta written now would fix requirements over a mapping
nobody has made and over three field drops nobody has approved. The failure
is the accurate signal. Do not silence it with a placeholder capability.

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
  schemas. THE REVERSIBLE HALF IS DONE, 2026-09-11. What remains is the
  destructive half, listed under "What step 4 left for step 5" at the end of
  this file. Was, before that: The irreversible act this note used to name, the
  three field drops, turned out to be done already (see 2a). What blocks it
  now is 2e and 2f: every part of the move runs through the tenant becoming
  an Organisation, and that needs a status to become. It no longer inherits
  2g: the four lookups that read OpenRegister rows as arrays were fixed
  together ahead of the move (see 2g). The pins in 2h are still what shows
  the re-pointed filters scope. The upstream half,
  `legalName` on `Organisation` (openregister#3603), does not depend on the
  status question and is not held by it.
- [ ] 5 **Remove the surface.** The `Tenants` and `TenantDetail` pages are
  both still in `src/manifest.json`. They go once the store they administer is
  gone, not before.

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
        legal name).
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
- [x] 2e Decided by option (c), and it is no longer hypothetical: openregister
      GREW the terminal state. Measured 2026-09-11 on
      `ConductionNL/openregister@development`,
      `lib/Service/TenantLifecycleService.php`. `STATUS_RETAINED = 'retained'`
      is entered from `active` or `suspended`, the same two states
      `deprovisioning` is entered from, and its only exit is
      `deprovisioning`, taken on purpose when retention ends.
      `PURGEABLE_STATUS` is `STATUS_ARCHIVED` alone, declared beside the
      transitions so deletability is a property of the lifecycle rather than
      of the job, and `TenantPurgeJob` re-checks every row against it. So a
      retained organisation is never purged. `retain()` stamps `retainedAt`
      and leaves `deprovisionedAt` untouched, which is the column the purge
      window is measured from.
      The migration maps `terminated` to `retained`, stamps `retainedAt` from
      the tenant's own `terminatedAt` so an old retention period is not reset
      to today, and writes no `deprovisionedAt`. No longer blocks step 4.
- [x] 2f Decided 2026-09-11: the Organisation stays `active` and dossiq's
      onboarding state is kept beside it, in `tenantOnboardingTask`. Not
      `provisioning`, for exactly the 403 recorded in 2d: while a user's
      active Organisation is `provisioning`, openregister's
      `TenantQuotaMiddleware` answers 403 to every request that user makes on
      openregister's routes unless they are an instance admin, and dossiq's
      frontend reads and writes through those routes. A dossiq tenant in
      `onboarding` is one whose tenant admin is still working through the
      onboarding steps, so it must be able to work. No longer blocks step 4.
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

## What step 4 left for step 5, the destructive half

Nothing below has been done, and none of it is reversible by a revert alone.

- Retire the `tenant` schema. Blocked on the two references above,
  `tenantOnboardingTask.tenantRef` and `automaticAction.tenantId`, which
  resolve to nothing once it goes.
- Remove the legacy fallback in `TenantOrganisationResolver::resolve()`, and
  `TenantSaasService`'s reads and writes of the `tenant` schema with it.
- Run the migration against real data, act on the collision and orphan
  reports, and only then delete stored `tenant` rows.
- Remove the `Tenants` and `TenantDetail` pages from `src/manifest.json`.
- Decide what happens to `TenantSaasService::LIFECYCLE_TRANSITIONS`, which is
  still dossiq's own four-state vocabulary and still drives the admin surface.
