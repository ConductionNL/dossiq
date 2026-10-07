---
kind: code
depends_on: []
---

# Proposal: declared-prerequisites

Competitor gap register, row Q12.25 "Does the product state its own
deployment prerequisites" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13). Rated no, owner dossiq, size
S. Re-read on 2026-09-13: the gap stays and the register's note is stale.
`easter_date()` is gone (`grep -rn easter_date lib/` hits only the header of
`lib/Service/WorkingDayCalculator.php`, which explains why); the row is a
gap because nothing states what dossiq needs.

## Why

What dossiq needs to run is written in four places and they disagree.
`composer.json` requires `php ^8.3` and `ext-zip`; `appinfo/info.xml`
declares PHP 8.3 and Nextcloud 32 minimum; `README.md` line 157 says
Nextcloud 28 to 34; the `#Integrations` page knows which sibling apps each
connection needs (REQ-ADMIN-021) but nothing lists the apps dossiq itself
wants. An administrator learns of a missing extension from a stack trace.

The best competitor in the register: OpenProject 16,
`app/controllers/admin_controller.rb:136-145` reports the binaries
full-text extraction needs (`_round4/compare/proposed-rows.md`).

## What changes

- One declaration, `lib/Prerequisites.php`: PHP version and extensions,
  the Nextcloud range, the required app (openregister) and the optional
  apps with what each unlocks (integriq, filinq, humaniq, decidiq,
  portaliq, pipelinq, hermiq, thematiq).
- The admin settings section shows a Prerequisites block: each item with
  present or missing, read live (`extension_loaded`, `IAppManager`).
- `composer.json`, `info.xml` and the README table are checked against the
  declaration by a unit test, so the four places cannot drift again. The
  README's 28 to 34 is corrected to what `info.xml` says.

## Ownership

dossiq builds all of it. It consumes AppHost's `GenericAdminSettings`
(ADR-076) for the block's placement, shipped.

## ADRs

- Company ADR-079: the block lives in the Nextcloud settings framework, not
  an in-app page.
- Company ADR-076: it is a section of the AppHost settings plane.
- Company ADR-102: a missing prerequisite is reported, never silently
  degraded.
- Company ADR-090: dependency integrity gates read the same declaration.

## Capabilities

- Modified: `admin-settings`: the prerequisites are declared once and shown.

## Impact

New `lib/Prerequisites.php`; `lib/Settings/*Admin.php` section; README
Requirements table; `tests/Unit/PrerequisitesTest.php`.
