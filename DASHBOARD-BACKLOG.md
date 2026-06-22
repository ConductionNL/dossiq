<!-- SPDX-License-Identifier: EUPL-1.2 -->
# Procest dashboard & IA backlog

Follow-ups captured 2026-06-22 after adding the KPI date-range pills.
Status updated 2026-06-22 after the first execution pass.

## 1. Resolve manifest schema-drift errors — ✅ DONE
Root cause: `tests/validate-manifest.js` validated the **v2** manifest against
the stale **v1** schema in node_modules (73 false errors). Against the correct
v2 schema only 5 real errors remained — the metric `cacheTtl` property, which is
a genuine OpenRegister AppHost MetricsEngine feature present in hydra's canonical
v2 schema (2.10.0) but missing from nextcloud-vue's published copy.
Fix: vendored the canonical v2 schema to `tests/schemas/app-manifest-v2.schema.json`
and pointed the validator at it. `node tests/validate-manifest.js` → PASS (0 errors).
Remaining (separate repo): add `cacheTtl` to the metric def in
`nextcloud-vue/src/schemas/app-manifest-v2.schema.json` so the published copy
matches hydra canonical.

## 2. Create dashboard test data — ✅ DONE (live) / repeatable seed = remaining
Seeded via the OpenRegister API (register 17): 5 `case` objects (schema 92) with
`startDate` spread across this-week / this-month / this-quarter / this-year / 2025
(one already overdue), and 3 `task` objects (schema 74) assigned to `admin` with
varied `dueDate`. This exercises the KPI pills across every range and populates
the My Tasks / Deadline / list widgets.
Remaining: capture these as a repeatable seed script/JSON (e.g.
`tests/fixtures/dashboard-seed.json` + an `occ` or API seeder) so the data is
reproducible after a `clean-env`.

## 3. KPI cards → native nc-vue widgets — ✅ DONE
Migrated the 4 custom KPI widgets to declarative nc-vue `type:"stat"`
(`CnStatWidget`) tiles + a shared dashboard `config.dateRange` pills control
(Week/Maand/Kwartaal/Jaar/Alles). Required upgrading `@conduction/nextcloud-vue`
108→125 (the version that added "publish date-range window to workspace context
so pills re-scope KPI tiles"; shillinq @111 still hand-rolls this). Counts are
now server-side via OpenRegister's `/value` aggregation, filtered by
`@workspace.dateFrom?`/`@workspace.dateTo?` tokens (+ `@me`, `@today`). Deleted
the 4 `*KpiWidget.vue`, `KpiRangePills.vue`, `utils/dateRange.js`,
`dateRange.spec.js` and the registry entries.
GOTCHA: `stats-block` (`CnStatsBlockWidget`) does NOT inject the workspace
context — it sent the raw `@workspace.*` token unresolved (all zeros). Use
`type:"stat"` (`CnStatWidget`) for date-range-filtered KPIs (it has the
`cnWorkspaceContext` inject + resolveFilterTokens/dropOptionalUnresolved), as
pipelinq does.
Trade-off (accepted): one SHARED dashboard range (header pills) instead of the
previous per-card independent pills. Semantics shifted slightly to be
declaratively expressible: Open→"Nieuwe zaken" (created in range), "Te laat"
(deadline<today AND created in range), "Afgerond" (closed in range), "Mijn
taken" (assignee=@me, due in range). Live-verified: counts resolve and re-scope
(Maand→Jaar: 5→12 / 2→6).
FOLLOW-UP: the 6 list widgets + 2 chart widgets are still custom; they could
move to CnObjectListWidget / CnChartWidget in a later pass.

## 4. Move Settings entries into the Settings menu — ◑ MOSTLY ALREADY DONE
The config leaves already carry `"section": "settings"` and a top-level
`SettingsGroup` (now "Instellingen") exists. The genuinely-settings items
(Zaaktypen, Parafeerroutes, Organisaties, Werkstroomdefinities, Statusgeschiedenis,
Kaartlagen, Zaaklocaties, Vervanging, etc.) are already in `section: settings`.
Remaining (judgment): a few leaves without a section that are arguably config
(Legesberekeningen, Termijnbewaking, Bezwaaradviescommissies) — decide per item
whether they are operational or settings, and whether `SettingsGroup` should
become a real nested parent rather than a flat `section` foldout.

## 5. Bezwaren/Beroepen as case types — ⚠️ DEFERRED (needs data re-model)
The bezwaar/beroep caseTypes already exist (seeded), BUT "Bezwaren"/"Beroepen"
nav leaves currently list their **own** schema objects (`bezwaar` 116 / `beroep`
122), not `case` objects with caseType=bezwaar/beroep. Deleting the nav leaves
now would hide that data with no replacement. Proper change = migrate/relate the
bezwaar/beroep records to `case` objects under the bezwaar/beroep caseTypes (or
add quickFilters to the Cases view scoped to those caseType UUIDs) before
removing the leaves. Raise as an OpenSpec change with a migration step.

## 6. Language consistency (Dutch canonical) — ◑ NAV DONE / dashboard+l10n remaining
Done: translated all English **nav menu labels** (literal strings) to Dutch —
Mijn werk, Werk, Zaken, Alle zaken, Werkvoorraad, Werkstroombord, Overdrachten,
Rapportages, Kaart, Advies, Instellingen, Documentatie, Zaaktypen,
Partnerorganisaties, Organisaties, Werkstroomdefinities, Statusgeschiedenis,
LHS-aanbevelingen, Zaaklocaties, Organisatie-onboarding, Vervanging,
Vervangingen & hertoewijzing, Functies & roadmap, Veldinspecties (+ inspectie
page titles).
Remaining: the dashboard widget titles and other UI strings rendered via
`t('procest', 'English')` are an l10n concern, not literals — the correct fix is
completing the Dutch `l10n/nl.*` translations (the dev instance also runs the
English locale, so Vue `t()` strings show English here regardless). Plus a sweep
of register/schema JSON titles. Do as an l10n PR.

## 7. Besluitvorming + decidesk — ✅ DONE (decision recorded)
See `docs/decisions/besluitvorming-vs-decidesk.md`. Recommendation: keep separate
(voorstel = pre-decision routing; decidesk = formal governance decisions);
optional one-way downstream integration only if demand warrants. No code change.

## 8. Field inspections — ✅ DONE (decision recorded)
See `docs/decisions/field-inspections-ownership.md`. It is an offline mobile
field-inspection workflow (domain), NOT data quality — stays in procest. Only
the label was changed ("Field inspections" → "Veldinspecties") under #6.

## 9. Rename "Analytics" -> Reports — ✅ DONE
Nav group "Analytics" → "Rapportages" (Dutch for Reports). Its single child
remains "Doorlooptijd" (SLA compliance dashboard with donut/histogram/trend/
throughput charts). Further consolidation into the fleet Reporting pattern is
optional follow-up.
