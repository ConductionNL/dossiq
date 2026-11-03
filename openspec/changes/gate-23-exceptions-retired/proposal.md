# Proposal: gate-23-exceptions-retired

Status: proposal for a decision. No code until Ruben picks an option per cluster.

## Why

Gate 23 (`or-abstraction-anti-patterns`) names 19 dossiq files under three rules:

- rule 2: audit trail classes;
- rule 4: `Tenant*` classes;
- rule 5: state machines and workflow engines.

On CI it passes, because two exception ADRs cover most of them:

- ADR-004 covers the tenant cluster (rule 4) and expires on 2027-03-31.
- ADR-005 covers the workflow and transition services (rule 5) and expires on 2027-01-31.

When an exception expires, the gate counts its paths again. dossiq development then turns red with no code change. SIG will also read these as app-local copies of platform capabilities. This proposal sets out, per cluster, what retiring each exception takes, the options, and the risks.

A local check with the vendored `conduction/hydra-gates` v1.16.1 reports gate 23 as FAIL. That version predates the per-rule exception path. CI runs the current gate. This is a stale local tool, not a red on development.

## What the files are today

### Tenant cluster (rule 4, ADR-004): 14 files, about 4,400 LOC

These already consume OpenRegister. The rule flags them by name only.

- `TenantService` and `TenantOrganisationResolver` read OpenRegister's organisation.
- `TenantOnboardingService` and its controller run on OpenRegister's task engine.
- `TenantSessionService` and `TenantContext` read OpenRegister's active organisation.

Decision 2b keeps these in dossiq on purpose:

- `TenantConfigurationService`, `TenantBrandingSanitiser`: branding, domain, locale and feature flags have no column on the organisation.
- `TenantQuotaService`: two of the four quota types map onto the organisation's `storageQuota` and `requestQuota`. Two do not.
- `TenantBillingService`, `TenantBillingController`: billing is shillinq's domain.

Kept until OpenRegister has a per-membership role and mandate matrix (Q2):

- `TenantAuthenticationService`

Kept for one release only:

- `TenantMigrationService`: task 6.4 of `tenancy-onto-openregister-organisation` deletes it once the repair step reports zero unmigrated tenants.

### Audit (rule 2): 3 files, about 1,030 LOC

- `TenantAuditTrailService` writes through OpenRegister's `AuditTrailMapper`. ADR-004 covers it under rule 4, but no ADR covers it under rule 2.
- `BezwaarAuditTrail` writes through `AuditTrailMapper`. It also owns the Awb and AVG tag vocabulary that every reader of these entries depends on.
- `CopyEmbeddedBezwaarAuditTrail` is a repair step. It copies the embedded arrays of older objection records onto the OpenRegister trail.

### Workflow and status (rule 5, ADR-005): 2 files named by the gate, about 1,000 LOC

- `StateMachineService` holds the decision machine: six states, one back edge, immutable from signed. A declared `x-openregister-lifecycle` validates exactly this shape on save. It is a duplicate, and no change retires it yet.
- `StatusTransitionService` reads its edges from the `workflowTemplate` objects of each case type, with per-edge guards, authorization and actions. OpenRegister has no lifecycle whose edge list lives in a related definition object, so there is nothing to consume yet. DQ4's dossiq#5009 is splitting this class now.

## Options

### Option A: retire what has a target, record the rest as a permanent boundary

1. `StateMachineService`: declare the decision lifecycle on the register, move the "immutable from signed" rule into a guard, and delete the class. This is the only clear duplicate.
2. `CopyEmbeddedBezwaarAuditTrail`: delete it once its dry run reports zero embedded arrays left on any instance.
3. `TenantMigrationService`: delete it at task 6.4, as already planned.
4. Turn ADR-004 and ADR-005 from sunsetting exceptions into boundary decisions without a sunset. They would cover:
   - the decision 2b satellites;
   - the authentication matrix;
   - the consumers flagged by name only;
   - `StatusTransitionService`, until OpenRegister has a definition-driven lifecycle.
5. Add rule 2 to ADR-004 for `TenantAuditTrailService`, and write an ADR for `BezwaarAuditTrail` that names its tag vocabulary as the reason it stays.

Cost: small, about 500 LOC removed and three ADR edits. Risk: SIG still sees the kept classes, and a boundary ADR needs Ruben to accept that these capabilities stay in dossiq.

### Option B: move the missing capabilities into OpenRegister first

1. OpenRegister gains:
   - a per-membership role and mandate matrix on the organisation;
   - the two missing quota types;
   - a definition-driven lifecycle: an edge list read from a related object, with per-edge guards and actions;
   - a bulk transition endpoint.
2. dossiq then deletes or thins `TenantAuthenticationService`, `TenantQuotaService` and `StatusTransitionService`, and drops ADR-005.
3. Billing and configuration stay in dossiq, as decision 2b says. ADR-004 shrinks to those.

Cost: large and cross-repo. It needs an openregister change of its own for each capability, and migrations for stored rows. Risk: access (the role and mandate matrix), money (quota and billing hooks) and every status move go through new code. All of it is REVIEW.

### Option C: Option A now, Option B one capability at a time

Do A in full now, so nothing depends on a sunset date. Then open one openregister change per missing capability, in order of value:

1. the definition-driven lifecycle, which retires the largest file;
2. the membership matrix;
3. the quota types.

Retire each dossiq class when its capability lands.

## Risks for every option

- Gate 23 matches file names. Renaming a class to clear it changes no behaviour and is refused: ADR-004 already says why.
- An exception that expires unattended turns development red on its sunset date. Whichever option is chosen, its ADR edits land before 2027-01-31.
- `StatusTransitionService` is in DQ4's open PR #5009. Nothing here touches it until that PR merges.
- OpenRegister strips undeclared properties on the next save. Any schema change in option B moves stored data before it removes a declaration.

## Recommendation

Option C. It removes the one real duplicate now, stops relying on sunset dates, and leaves each larger move as its own reviewed change.

## Decisions asked

1. Option A, B or C.
2. Do the decision 2b satellites, the authentication matrix and the definition-driven status engine become permanent boundaries (no sunset), or do they stay sunsetting exceptions?
3. Under option B or C: which OpenRegister capability comes first?
