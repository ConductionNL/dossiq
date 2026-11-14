# Proposal: case-type-rights-by-role

## Why

Board DqZaaktypeOverzicht draws the Rechten card as one row per role: Rol, Zaken zien, Behandelen, Besluiten,
Documenten. The live card showed the stored rights matrix as it is stored (Role, Department, Confidentiality,
Actions, Until). The board is canon (coordinator 23 Oct); the matrix stays the source and stays reachable.

## What changes

- `CaseTypeRightsByRole` reads a case type's `rightsMatrix` per role: See cases = the departments of its read/list
  rows (All for `*`), Handle = update, Decide = a `decide` verb, Documents = All (delete) / Add (create) / Read
  (read) / No. A row past its `until` grants nothing.
- `GET /api/case-types/{id}/rights-by-role` answers `{ rows }` (read only, same posture as the terms read).
- The Overview's Rights card is `case-type-rights-summary`, a record-list table over that endpoint, with Edit opening
  the Rights tab; the Rights tab keeps the full matrix (`case-type-rights`).

## Lossy mapping

Written to R20/DECISIONS-FOR-RUBEN.md: "Eigen team", phase scopes ("Fase 1") and mandate limits ("Tot € 50.000")
are not in the matrix; confidentiality levels merge per role.

## Reach

PHP service, controller, route; config and l10n. No schema change.
