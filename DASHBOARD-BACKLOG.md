<!-- SPDX-License-Identifier: EUPL-1.2 -->
# Procest dashboard & IA backlog

Follow-ups captured 2026-06-22 after adding the KPI date-range pills.
Each item is a TODO, not yet implemented.

## 1. Resolve manifest schema-drift errors
`node tests/validate-manifest.js` reports ~73 `additionalProperties` schema
violations on `src/manifest.json` pages ~47–62 (and some `config/actions`
entries). Pre-existing — not introduced by the pills work. Either tighten the
manifest to match the schema or update the schema if the extra properties are
legitimately supported. Make the validator green so it can gate CI.

## 2. Create dashboard test data
Seed representative cases/tasks (varied caseType, status, startDate, endDate,
deadline, dueDate, assignee) so every dashboard widget — KPIs, Cases by
Status/Type, Open/Overdue lists, Deadline Alerts, Task Due Reminders, Stalled
Cases — renders real, non-empty data. Needed to prove the widgets (and the new
range pills) work across all ranges. Prefer an OpenRegister seed/import over
ad-hoc manual entry so it is repeatable.

## 3. Dashboard should have no custom widgets
Audit the dashboard widgets and remove/replace any bespoke custom-component
widgets in favour of the standard nc-vue widget library + manifest config, so
the dashboard is fully declarative and consistent with the other apps.

## 4. Move Settings entries into the Settings menu
The left-nav has app config leaves that belong under the platform Settings
(Personal/Admin settings) rather than as in-app navigation. Move the
settings-style entries into the Settings menu group, matching the fleet
admin-settings IA pattern.

## 5. Bezwaar & Beroep — model "Bezwaren" and "Beroepen" as case types
"Bezwaren" and "Beroepen" are currently their own nav leaves but are really
case *types*, not separate object collections. Re-model them as caseType
records and surface them through the standard Cases views/filters instead of
dedicated menu items.

## 6. Language consistency pass (Dutch vs English)
The app mixes Dutch and English in nav, manifest, and code (e.g. "Bezwaar &
Beroep", "Besluitvorming", "Legesberekeningen", "Portaal" alongside "Open
Cases", "Field inspections", "Analytics"). Decide the canonical language
(Dutch for the gov domain UI is likely) and align manifest.json, register JSON,
and code strings + l10n accordingly.

## 7. Besluitvorming — research integrating with decidesk
The Besluitvorming section (Agenda / Advice / Voorstellen) overlaps decidesk's
decision/meeting domain. Research and propose whether Besluitvorming should
consume decidesk (its objects/UI) instead of re-implementing in procest, per
the fleet "decisions/contracts -> decidesk" consolidation pattern.

## 8. Field inspections — clarify purpose / ownership
Determine what "Field inspections" actually does and whether procest needs it.
If it is about data quality, justify why it lives in procest rather than
OpenRegister (which owns data quality). Either document the rationale or move
it to OpenRegister.

## 9. Rename "Analytics" -> "Reports" (Reporting)
The "Analytics" section is really reporting. Rename it to Reports/Reporting and
align with the fleet Reporting & Compliance pattern (report views as cards).
