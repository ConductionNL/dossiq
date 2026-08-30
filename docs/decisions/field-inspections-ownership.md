<!-- SPDX-License-Identifier: EUPL-1.2 -->
# Decision: Field inspections — keep in dossiq (it is domain, not data quality)

Status: **Recommendation** (2026-06-22) — backlog item #8.

## Question
What does "Field inspections" (nav `Inspecties`, route `/inspecties`) do, is it
needed, and — if it concerns data quality — why is it not in OpenRegister?

## What it is
A **mobile, offline-first field-inspection workflow** for inspectors, defined in
`src/manifest.d/70-mobiel-inspectie.json` with views under
`src/views/inspectie/` (`InspectieList.vue`, `InspectieDetail.vue`) and helpers
in `src/utils/fieldInspectionHelpers.js`. It:
- loads the inspector's daily planning from local **IndexedDB** (synced via
  `GET /apps/dossiq/api/sync/daily`),
- renders a checklist template per inspection, validates required answers,
- captures **GPS per answer**, photos (≤2 MB), and voice memos (≤5 min),
- stores answers atomically offline and queues a `ChecklistResult` for sync.

Backing schemas: `inspectie_checklist` (133), `inspectie_rapport` (134),
`inspection_checklist_template` (135), `inspection_checklist_run` (136).

## Answer
- **What:** on-site case inspections (e.g. building-permit / enforcement /
  public-space checks) performed by field workers, offline with evidence
  capture. It is operational case work.
- **Is it needed:** yes — it is genuine dossiq domain functionality, distinct
  from the desktop case views.
- **Move to OpenRegister?** **No.** This is **not** data-quality monitoring
  (schema validation / missing-field audits) — which is what OpenRegister owns.
  It is a domain procedure with deeply dossiq-specific UX: offline IndexedDB
  sync, checklist templates, GPS-tagged evidence, photo/voice capture. The
  inspection *results* are already OpenRegister objects (schemas above); the
  workflow that produces them belongs in dossiq.

## Note
The only change applied here is the label: "Field inspections" → "Veldinspecties"
is part of the language-consistency pass (backlog #6). No relocation.
