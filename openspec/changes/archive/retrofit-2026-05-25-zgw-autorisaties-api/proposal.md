# Retrofit — zgw-autorisaties-api

Describes observed behavior of the 16 methods in `lib/Controller/AcController.php` as 5 new REQs under a new `zgw-autorisaties-api` capability. Code already exists — this change retroactively specifies it.

## Why a new capability (not --extend zgw-api-mapping)
The coverage scan labeled this cluster `bezwaar-advisory-committee`, guessing "AC" = Adviescommissie. That is a **mislabel**. `AcController` is the VNG ZGW **Autorisaties (AC) API** — the sixth ZGW component, managing *applicaties* (API consumers + their scopes). It is distinct from the existing `zgw-api-mapping` capability, which covers the five *data* APIs (ZRC/ZTC/DRC/BRC/NRC) and is built on OpenRegister's Twig mapping engine. AC uses none of that engine; it maps directly to/from OpenRegister `ConsumerMapper` entities. Minting a new capability is the correct call.

## Relationship to the archived aspirational spec
An earlier change, `archive/2026-03-15-zgw-autorisaties-api`, authored an *aspirational* VNG AC contract under the capability slug `zgw-autorisaties`. That spec was **never promoted into `openspec/specs/`** and no code carries a `@spec` ref to it. Notably it mandates a "Scope Enforcement" requirement (`*.lezen`/`*.schrijven` per scope) that the shipped `AcController` does **not** implement (see REQ-005 notes — JWT is authenticated but scopes are never checked). This retrofit deliberately captures *observed* behavior under a distinct slug `zgw-autorisaties-api` rather than reviving the aspirational `zgw-autorisaties` spec, to avoid conflating "what the code does" with "what VNG requires". The scope-enforcement divergence is the headline gap for a future authorization-hardening change.

## Affected code units
- lib/Controller/AcController.php — index, create, show, update, patch, destroy, findConsumerByUuid, validateApplicatieBody, validateClientIdUniqueness, validateAutorisatieConsistency, validateAutorisatieScopes, scopesContain, getConsumerClientIds, consumerToApplicatie, applicatieToConsumer (+ __construct)

## Approach
- Read every method; describe observed inputs, outputs, pre/postconditions, failure modes.
- Draft REQs that match behavior (not aspirational):
  - REQ-001 — CRUD contract + applicatie↔consumer mapping
  - REQ-002 — ac-001 clientId uniqueness
  - REQ-003 — ac-002 heeftAlleAutorisaties/autorisaties consistency
  - REQ-004 — ac-003 scope-based field requirements
  - REQ-005 — JWT auth + safe degradation
- Notes sections surface observed-but-suspicious behavior — most importantly the authorization gaps (no scope check; write-path skips validation; UUID-only object resolution with no ownership guard). These are documented, NOT fixed, per retrofit guardrails.

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
