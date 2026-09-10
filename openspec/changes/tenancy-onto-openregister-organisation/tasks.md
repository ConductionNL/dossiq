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
- [ ] 4 **Move.** Repoint the five middlewares, migrate the data, retire the
  schemas. NOT STARTED, AND NOT TO BE STARTED BY AN AGENT ON ITS OWN
  JUDGEMENT. It contains the one irreversible act in the change: dropping
  `contractRef`, `isolationMode` and `dataResidency`. The proposal's own rule
  is that each is verified against STORED DATA before removal and not only
  against readers, and stored data is per install, so no reading of this
  repository can discharge it. It needs a person to say yes.
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

- [ ] 2a Decide `legalName` (own column on Organisation, or fold into
      `description`) and `tier` (own column, or derive from quota).
- [ ] 2b Decide the five satellites: grow OpenRegister, re-point at
      `Organisation`, or drop.
- [ ] 2c Re-file `tenantOnboardingTask` under the caseTask cluster.
- [ ] 2d Compare the `status` value sets before assuming the 1:1.
