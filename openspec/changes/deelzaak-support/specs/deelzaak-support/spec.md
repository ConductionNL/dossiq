# Spec: deelzaak-support

## Acceptance Criteria

### AC-1: Zaaktype deelzaaktype configuration
GIVEN a zaaktype configuration,
WHEN an administrator defines allowed deelzaak types via the `subCaseTypes` field,
THEN only those zaaktypen are selectable when a handler creates a deelzaak from a parent case.

### AC-2: Deelzaak inherits parent fields
GIVEN a parent case,
WHEN a handler creates a deelzaak (manually or automatically),
THEN the deelzaak inherits `deadline` (from `processingDeadline` on the caseType) and
`archiveNomination` from the parent, and `parentCase` is set to the parent's ID.

### AC-3: Automatic deelzaak on trigger status
GIVEN a zaaktype with a workflow transition action `createSubCase`,
WHEN the parent case reaches the configured trigger status,
THEN the deelzaak is created automatically by `DeelzaakService::createDeelzaak()`.

### AC-4: 3-level hierarchy tree
GIVEN a parent case with deelzaken that themselves have deelzaken (3+ levels),
WHEN a user views the case detail page,
THEN the `DeelzaakHierarchyTree` component renders all levels recursively
with a status badge per case.

### AC-5: Closure guard
GIVEN a parent case configured to require deelzaken completion (`requireAllDeelzakenClosed`),
WHEN the handler attempts to close (set endDate on) the parent case,
THEN `DeelzaakService::validateClosureAllowed()` returns `false` with a list of open deelzaken,
and the UI shows a blocking error listing each open sub-case.

### AC-6: Vervolg-zaak creation
GIVEN a case reaching a trigger status with `aardRelatie=vervolg` configured,
WHEN `DeelzaakService::createVervolgzaak()` is called,
THEN a new follow-up case is created, the original case gets `relatedCases` updated with
the successor URL, and the new case has `relatedCases` updated with the predecessor URL.

## API Contracts

### POST /api/procest/deelzaak/{caseId}
Creates a deelzaak from the given parent case.

Request body:
```json
{
  "caseTypeId": "<uuid>",
  "title": "optional override",
  "assignee": "optional"
}
```

Response: `201 Created` with the created case object, or `409 Conflict` with error detail.

### GET /api/procest/deelzaak/{caseId}/hierarchy
Returns the full case hierarchy tree rooted at `caseId`.

Response:
```json
{
  "case": { ...caseFields },
  "children": [
    {
      "case": { ...caseFields },
      "children": [ ... ]
    }
  ]
}
```

### GET /api/procest/deelzaak/{caseId}/closure-check
Returns whether the case can be closed.

Response:
```json
{
  "canClose": false,
  "openDeelzaken": [
    { "id": "<uuid>", "title": "...", "status": "..." }
  ]
}
```

### POST /api/procest/deelzaak/{caseId}/vervolgzaak
Creates a vervolg-zaak from the given case.

Request body:
```json
{
  "caseTypeId": "<uuid>",
  "title": "optional"
}
```

Response: `201 Created` with the created vervolg-zaak object.
