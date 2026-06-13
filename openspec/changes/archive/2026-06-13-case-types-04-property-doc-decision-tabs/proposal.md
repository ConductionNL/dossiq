---
kind: code
depends_on: [case-types-03-result-role-tabs]
chain:
  - case-types-01-seed-and-stores
  - case-types-02-backend-validation
  - case-types-03-result-role-tabs
  - case-types-04-property-doc-decision-tabs
---

## Why

This is **member 4 of 4** (final) in the `case-types` chain (decomposed from the
oversized `case-types` change per ADR-032). Predecessor:
`case-types-03-result-role-tabs`. No successor — this member completes the chain.

The remaining V1 admin tabs are Property Definitions (custom mandatory fields with
format validation), Document Types (required document checklists tied to status
gates), and Decision Types (allowed decision types with objection and publication
periods). This member adds those three tabs to the tab framework established in
member 03, then runs the end-to-end smoke verification across all seven tabs.

It consumes the `property-definition`, `document-type`, and `decision-type` stores
registered in member 01 and the tab-integration framework in member 03.

## What Changes

- **REQ-CT-09**: Property definition management tab — CRUD for custom field
  definitions with format, `maxLength`, `allowedValues`, and `requiredAtStatus` gating.
- **REQ-CT-10**: Document type management tab — CRUD for document types with
  `direction`, `order`, `confidentiality`, and status gating.
- **REQ-CT-11**: Decision type management tab — CRUD for decision types with
  `objectionPeriod` and `publicationRequired` rules.
- **Tab integration (completion)** — add the three tabs to `CaseTypeDetail.vue`
  alongside the Results/Roles tabs from member 03.
- **End-to-end smoke verification** — confirm all seven tabs render and CRUD works
  (the giant's TASK-CT-13 browser verification).

## Impact

- **Frontend**: three new Vue tab components in `src/views/settings/tabs/`:
  `PropertiesTab.vue`, `DocumentTypesTab.vue`, `DecisionTypesTab.vue`.
- **Frontend**: `src/views/settings/CaseTypeDetail.vue` — add the Properties, Docs,
  Decisions tab entries to the framework from member 03.
- **No new schemas, no backend changes** in this member.
- Consumes member-01 store registrations and member-03 tab framework.
