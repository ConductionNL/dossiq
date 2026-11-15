---
kind: code
depends_on: [case-definition-packages, bezwaar-beroep-package]
---

# Proposal: besluitvorming-package

## Why

Hydra ADR-118 (decisions 182, 184, 185): a procedure is configuration. The college-besluit, raadsbesluit and mandaatbesluit case types arrived through code of their own: `BesluitvormingTemplateService` read three bundles from `lib/Settings/templates/bvw-*.json`, `Besluitvorming/TemplateBundleSeeder` and `Besluitvorming/WorkflowReferenceResolver` wrote them with random ids and resolved status and role names at install time, `SeedBesluitvormingTemplates` ran that on every install and upgrade, and `BesluitvormingController` exposed an activate route. The bundles have been marked deprecated since decision types moved to decidiq. Decision 190: they become an on-request package, and the installer adopts what the seeder already wrote.

## What changes

- The three case types ship as the package `lib/Settings/packages/besluitvorming/`, `install: on-request`, converted once by `scripts/packages/seed-to-package.py` (fixed UUIDs, status and role names resolved, `initialStatusName` resolved to `initialStatus`, flows published so cases can run on them). The converter now also carries property definitions, document types and result types, reads one case type or a list, and gives a case type without an id a UUIDv5 of its identifier.
- A generic package API: `GET /api/packages` lists the shipped packages with their install mode and the version installed here, and `POST /api/packages/{id}/install` installs one (admin only). It replaces `POST /api/besluitvorming/templates/{slug}/activate` and serves every on-request package.
- On an instance where `SeedBesluitvormingTemplates` already ran, installing the package adopts the existing case types by identifier and their rows by name (case-definition-packages REQ-CT-51), so nothing is duplicated.
- Deleted: `BesluitvormingTemplateService`, `Besluitvorming/TemplateBundleSeeder`, `Besluitvorming/WorkflowReferenceResolver`, `SeedBesluitvormingTemplates`, `BesluitvormingController` and its route, the three `bvw-*.json` bundles, `BesluitvormingTemplateServiceTest` and `BvwTemplateConformanceTest`.

## Impact

- New instances no longer get the deprecated besluitvorming case types on install; an administrator installs them with `POST /api/packages/besluitvorming/install` or `occ dossiq:package:install besluitvorming`.
- `POST /api/besluitvorming/templates/{slug}/activate` is gone. No frontend called it.
- The template library (`/api/templates`) no longer lists the bvw bundles.
