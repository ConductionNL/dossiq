# Design: inspection-checklists

## Context

Inspectors run structured checklists on site. A bouwtoezicht-fundering checklist may have 30 items in 4 sections (Fundering, Wapening, Waterkering, Maatvoering), each with a response type (ja/nee/nvt, numeric range, multiple-choice, photo, free text). Templates are reusable; each inspection produces a `checklistRun` — an immutable record of one inspector's observations. This change owns the data model and behaviour; the PWA shell lives in sister spec `mobiel-inspectie`.

## Entities

### `checklistTemplate`

| Property | Type | Role |
|----------|------|------|
| `name` | string ≤ 255 | Label, e.g. "Bouwtoezicht — Fundering" |
| `caseType` | UUID ref | Optional binding; null = any case |
| `version` | int ≥ 1 | Immutable once a run uses it |
| `status` | enum: `draft`, `active`, `retired` | Lifecycle |
| `sections` | array | Ordered, name + description |
| `items` | array<`checklistItem`> | See below |

`checklistItem`: `{order, label, helpText, responseType (ja_nee_nvt|tekst|getal|meerkeuze|foto|meting), required, fotoRequired (nooit|bij_nee|altijd), numericRange {min,max,unit}, choices[], failureAction {type (herinspectie|handhavingstaak|documentVerzoek|geen), template, deadlineDays}}`.

### `checklistRun`

| Property | Type | Role |
|----------|------|------|
| `case` | UUID ref | Parent case |
| `template` | UUID ref | Template chosen at start |
| `templateVersion` | int | Frozen at run start (REQ-IC-8) |
| `templateSnapshot` | JSON | Frozen sections+items copy |
| `inspector` | NC UID | Server-derived from `IUserSession` |
| `startedAt` / `completedAt` | datetime | Lifecycle |
| `status` | enum: `concept`, `in_uitvoering`, `ingediend`, `gearchiveerd` | |
| `responses` | array | One per item |
| `location` | `{lat, lng, accuracy, source}` | From mobiel-inspectie |
| `overallResult` | enum: `conform`, `niet_conform`, `deels_conform` | Derived |
| `syncState` | enum: `local`, `queued`, `synced` | Offline tracking |

`checklistResponse`: `{itemId, value, numericValue, choice, comment, photos[], audio[], respondedAt}`. After `status = ingediend`, run is append-only — `ChecklistService::recordResponse()` rejects writes.

## Lifecycle

```
template: draft ──(publish)──► active ──(superseded)──► retired
run:      concept ──(first answer)──► in_uitvoering ──(submit)──► ingediend ──(retention)──► gearchiveerd
```

Submitted runs never re-open. Corrections require a new run; the prior run remains immutable evidence (Archiefwet).

## Offline sync

Mobile clients write through an IndexedDB queue keyed by `run.id`. `syncState` tracks per-response and per-photo progress. On reconnect: drain responses first (small idempotent JSON keyed by `{runId, itemId}`), then photos (chunked upload with backoff). Conflicts surface a chooser; local writes never silently overwrite. Mobiel-inspectie REQ-MOB-06 owns PWA UX; this spec owns the data contract.

## Evidence linking

Evidence lands under `/Procest/Zaken/{caseId}/Inspecties/{runId}/items/{itemId}/`. The response holds Nextcloud file IDs (not paths), so renames don't break links. Files inherit case ACL via folder sharing. Once `ingediend`, evidence is never moved or deleted.

## Pass/fail aggregation

`overallResult` is derived, never user-set:

- Item pass: `value ∈ {ja, nvt}` for ja_nee_nvt; within range for getal/meting; expected choice for meerkeuze; photo present for foto
- Item fail: `value = nee` or out of range
- Item skipped: `value = nvt`
- Run aggregate: `conform` (0 fails), `niet_conform` (≥1 fail, 0 skipped), `deels_conform` (≥1 fail and ≥1 skipped) — matching the existing main spec
- Per-section result computed identically; UI shows badge per section + overall

## Conditional follow-up actions

On submit, `ChecklistService::dispatchFollowUps()` walks failed items. For each item with `failureAction.type ≠ geen`, it creates a task on the parent case: a failed wapening item with `{type: herinspectie, deadlineDays: 14}` opens a herinspectie task; a failed brandveiligheid item with `{type: handhavingstaak}` hands off to `enforcement-lhs`. Tasks reference both run and item.

## Security & audit

- Inspector identity derived from `IUserSession`; frontend-supplied UIDs ignored
- Template edits gated by `procest_admin` group via `SettingsController`
- Run reads gated by case ACL (same as mobiel-inspectie)
- OpenRegister audit trail captures every write; submitted-run append-only enforcement layered on top
