# Tasks

## 1. Generic
- **spec_ref**: `openspec/changes/woo-five-steps/specs/case-type-version-chain/spec.md#requirement-a-case-carries-the-identifier-its-case-type-chain-shares`
- [x] 1.1 `case.caseTypeIdentifier` materialised from `@ref.caseType.identifier` (case schema 1.48.0)
- [x] 1.2 `BackfillCaseTypeIdentifier` on `BackfillReferencedCaseValue`, which `BackfillCaseStatusRole` now uses too
- [x] 1.3 "Woo requests" view, quick filter and six Woo-only actions on `caseTypeIdentifier`
- **spec_ref**: `openspec/changes/woo-five-steps/specs/woo-request-intake/spec.md#requirement-a-request-is-filed-on-the-current-version-of-the-case-type`
- [x] 1.4 `SystemCaseStore::currentCaseType`, used by `WooRequestIntake`
- **spec_ref**: `openspec/changes/woo-five-steps/specs/woo-request-intake/spec.md#requirement-every-version-of-the-woo-case-type-is-a-woo-request`
- [x] 1.5 A clarification on every version
- **spec_ref**: `openspec/changes/woo-five-steps/specs/case-type-version-chain/spec.md#requirement-a-chosen-case-type-follows-its-version-chain`
- [x] 1.6 The menu's chosen case types and its Woo default fold to the version in use

## 2. Configuration
- **spec_ref**: `openspec/changes/woo-five-steps/specs/case-type-version-chain/spec.md#requirement-the-woo-request-case-type-has-a-second-version-with-five-steps`
- [x] 2.1 Woo case type version 2 (register.d/81-woo-verzoek-version-2.json): five statuses, result, decision and property types, record import; version 1 superseded
- [x] 2.2 Flow flows/woo-verzoek-version-2.json with the guards regrouped; package 1.2.0
- [x] 2.3 StatusTypePalette: Publiceren green

## 3. Example data
- [ ] 3.1 The Zuiddrecht Woo cases and the Lindelaan sub-cases on version 2
