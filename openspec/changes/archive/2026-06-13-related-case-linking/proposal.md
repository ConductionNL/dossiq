# Proposal: related-case-linking

<!-- EXTENSION NOTICE
     Parent capabilities: case-management (relatedCases field), zgw-api-mapping (ZRC Zaak resource)
     This change exposes the existing `relatedCases` schema field through typed peer-relation
     behaviour, UI, and ZGW mapping. Do NOT define a new case entity or new CRUD — reuse what
     `case-management` already provides. -->

## Why

RGBZ and the ZGW ZRC standard model two kinds of zaak relations: hierarchical (hoofdzaak/deelzaak) and **peer relations** — `relevanteAndereZaken`, each typed with an `aardRelatie` (`vervolg`, `onderwerp`, `bijdrage`). Procest's `deelzaak-support` change covers the hierarchy only (its spec scopes itself to `parentCase`/`subCaseTypes`; all eight requirements are parent-child). The `case` schema already *carries* a `relatedCases` array mapped to `relevanteAndereZaken` (case-management spec field table), but nothing specifies its behaviour: no typed relations, no bidirectional consistency, no UI to link cases, and no ZRC mapping requirement for the field.

Peer links are daily zaaksysteem practice: a bezwaar must reference the original besluit-zaak (`onderwerp`), a WOO request references its bronzaken, a toezicht case follows a vergunning (`vervolg`), and an advies-zaak contributes to a hoofdbehandeling (`bijdrage`). Without them, handlers reconstruct context by searching, and ZGW consumers receive an empty `relevanteAndereZaken` even when relations exist.

## What Changes

1. Define the `relatedCases` element shape on the `case` schema: `{caseId, aardRelatie ∈ vervolg|onderwerp|bijdrage, toelichting}` — RGBZ-aligned, stored in the existing field (no new schema).
2. `CaseRelationService` — add/remove typed relations with bidirectional consistency (the relation is visible from both cases), guards (no self-relation, no duplicates, hierarchy not mirrored as peer relation), and dangling-reference cleanup on case deletion.
3. UI: a "Gerelateerde zaken" section on the case detail with an add-relation modal (case search, relation-type picker, toelichting) and navigation to related cases the user may access.
4. ZGW mapping delta: the ZRC Zaak resource maps `relatedCases` ⇄ `relevanteAndereZaken` (`[{url, aardRelatie}]`) bidirectionally, inbound and outbound.

## Impact

- `case-management`: behavioural specification of the existing `relatedCases` field (additive; the field and its ZGW name were already declared).
- `zgw-api-mapping`: one ADDED requirement for the `relevanteAndereZaken` mapping on the Zaak resource.
- New `CaseRelationService` + endpoints; `RelatedCasesSection.vue` + `AddCaseRelationModal.vue`.
- `deelzaak-support` untouched — hierarchy stays its own capability; this change explicitly refuses to duplicate parent-child links as peer relations.

## Out of Scope

- Hoofdzaak/deelzaak hierarchy, vervolg-zaak *auto-creation* on status triggers, and roll-ups (owned by `deelzaak-support`). Note: this change covers *manually linking* an existing case as `vervolg`; automatic spawning of follow-up cases remains in `deelzaak-support`.
- Cross-organization case links (zaak in another organisation's systeem) — `case-collaboration-cross-org` territory.
- Relation-driven workflow behaviour (e.g. blocking closure while a related case is open) — can be a later change on top of the relation data.
- Graph visualisation of case networks.
