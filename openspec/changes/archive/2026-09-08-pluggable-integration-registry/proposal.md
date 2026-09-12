---
kind: config
depends_on: []
---

# Proposal: pluggable-integration-registry

Round 2 competitor analysis, row A34 of
`concurrentie-analyse/procest/_round2/compare/placement.md`. This change
fills the proposal that stood empty here as a cross-repo stub: the
OpenRegister side (ConductionNL/openregister#1307) owns the registry of
pluggable integrations; this side owns what a dossiq admin sees of it. One
noun: the connections the app has to systems outside it.

## Why

Dossiq talks, or claims to talk, to ten outside systems: the ZGW APIs,
StUF-ZKN, the KCC desk, decision tables (DMN), the shared mailbox, the
Store, the financial system, BRP, KvK and PDOK. Today the only place that
shows any of them is `/settings/admin/dossiq`, seventeen sections in 12,371
characters with no navigation, two duplicated headings and 34 buttons
(`_round2/dossiq-baseline/admin-anatomy.md`). Whether a connection works is
not visible anywhere: the StUF table has a Health column with one row, the
mailbox has a Test connection button, and the rest show a form and nothing
else. Per `M3-integrations.md` the dossiq column is `partial, stub` for ZGW,
StUF, KCC, DMN, mailbox, Store, financial and PDOK, and `spec only` for BRP
and KvK. The admin page does not say so.

All three competitors put the connections on one page with a status:

- GZAC: `valtimo/round2/pages/Admin-Plugins.md`, one page, a table of
  configured plugin instances and a Plugin configureren wizard of tiles.
- OpenCase: `opencase/round2/pages/AdminSettings.md`, eight system sections.
- Zaaksysteem: `xxllnc-zaken/round2/pages/Koppelingen.md`, Voeg koppeling
  toe and a module list.

ADR-110 says a link that leaves the app leaves the navigation: integrations
are an in-app page, reached from the gear, not a scattering of links.
ADR-079 says app-level configuration lives only in the Nextcloud settings
framework. Both hold here: the Integrations page shows status and links to
the configuration, and the configuration itself stays where ADR-079 puts
it.

## What Changes

- A page `Integrations` of type `settings` in the gear foldout, admin only,
  with one card per connection showing its title, its status and a link to
  the section that configures it. The card tells the truth per connection:
  a connection whose spec has no implementation reads Not available, not
  Not configured.
- A config schema `dossiqIntegration` carrying the ten connections as
  objects: key, title, status, status message, checked at, settings link.
  The page reads its cards from that schema, so nothing about the list is
  hard-coded in a component.
- The existing StUF health check and mailbox Test connection write their
  result to the matching `dossiqIntegration` object, so the two connections
  that can be probed today show a probed status.
- The Nextcloud admin page gains an anchor per section so the card's link
  lands on the right section. It loses nothing in this change; shrinking it
  to version and register mapping is the follow-up once every section has a
  home under the gear.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `admin-settings`: an Integrations page under the gear lists every
  external connection with a status and a link to its configuration.

## Impact

- `lib/Settings/dossiq_register.json`: schema `dossiqIntegration` and its
  seed of ten rows.
- `src/manifest.json`: page `Integrations` and its menu entry;
  `src/menu-layout.json`: `IntegrationsMenu` in `settingsSection`.
- `lib/Service/StufHealthService.php` (or wherever the StUF health runs)
  and the mailbox test-connection controller: write the probe result.
- `src/views/settings/AdminRoot.vue`: `id` anchors on the sections.
- `l10n/en.json`, `l10n/nl.json`: the labels and status texts.
- E2E: `tests/e2e/integrations-page.spec.ts` (new).
- Adjacent: `leaf-integrations` (OpenRegister leaves on the case, not
  external systems); `dossiq-consumes-shared-dmn` and
  `kcc-routing-onto-or-decision-tables` move two of the ten connections to
  OpenRegister and will change those two cards' settings links, not the
  page.
