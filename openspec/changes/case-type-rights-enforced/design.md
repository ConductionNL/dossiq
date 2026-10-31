# Design: case-type-rights-enforced

## D-1. Row shape (caseType 1.34.0)

```json
{
  "department": "Vergunningen | * | $ownTeam",
  "role": "<organisatieRol id or roleName>",
  "confidentiality": "intern",
  "actions": ["read", "update", "handle"],
  "statuses": ["<statusType id>"],
  "documentActions": ["read", "add"],
  "until": "2027-01-01"
}
```

`statuses` empty or absent means every status. `documentActions` absent means "read the old verbs" (delete = all, create = add, read = read) so stored rows keep their meaning.

## D-2. From a rights row to OpenRegister matrix rows

`CaseTypeRightsRows` listens for `MatrixRowsRequestedEvent` with provider `dossiq.case-type-rights`. It reads, without RBAC, the current case types (not draft, not superseded) and the organisation roles, once per request.

| Rights row | Matrix row |
|---|---|
| `role` | `group` = that role's `organisatieRol.ncGroupId`. No group: row dropped, reported on the Rights tab |
| `department: "*"` | value = every group of every role (all teams) |
| `department: "<name>"` | values = `ncGroupId` of the roles whose `department` is that name |
| `department: "$ownTeam"` | value `$self` (the caller's groups, on `assignedGroup`) |
| case type | `where.caseType` = the case type id |
| `confidentiality` L | `where.confidentiality` = `$in` the levels up to and including L |
| read verbs (`read`, `list`) | a row with `read`, no status condition |
| edit verbs (`update`, `handle`, `create`) | a row with those verbs and `where.status` = `$in statuses` when `statuses` is set |
| `documentActions` | a row with `readFiles` / `addFiles` / `deleteFiles` |
| `until` | `until` |

A row whose values resolve to nothing is not emitted (OpenRegister also drops it; dossiq drops it first so the Rights tab can say why).

## D-3. Base file verbs on the case schema

The case schema names `readFiles`, `addFiles` and `deleteFiles` for the assignee and `dossiq-background-service`, so file verbs are always declared for cases and the matrix's document rights are the only other way in. Without this, a case type with no document rows would fall back to the object rule and the opt-in would differ per case type.

## D-4. Besluiten from the mandate register

Per role: the active mandates (`status: active`, today inside `validFrom`..`validUntil`) whose `mandateeRole` is the role, whose `terms.caseTypes` is empty or holds this case type. Highest `terms.plafondCents` reads "Tot € 50.000"; a mandate without a ceiling reads "Onbeperkt"; none reads "Nee". The window and case type checks reuse `MandaatCheckService`'s helpers rather than a second reading.

## Access tests

PHPUnit through OpenRegister's real `PermissionHandler` and `MagicRbacHandler` with dossiq's listener registered, on the seeded Omgevingsvergunning regulier rows:

1. Intaker, `$ownTeam`, statuses [Ontvangen], documents add: reads cases of their own team only; edits in Ontvangen, refused in In behandeling; uploads a file, refused on delete.
2. Vergunningverlener, `*`, every status, documents all: reads and edits every team's cases; deletes a file.
3. Toezichthouder, `*`, read only, documents read: reads; refused on edit and on upload.
4. A case type with no rows: today's access (assignee only).
5. An expired row admits nobody; a role without `ncGroupId` admits nobody and is reported.
6. The assignee edits their own case outside the row's statuses.

## Decisions (Ruben, 23 October 2026)

- A row at a confidentiality level also covers the lower levels.
- Outside a row's statuses every edit verb stops, create included, and so do adding and deleting documents; reading does not. The assignee keeps their own rights.
