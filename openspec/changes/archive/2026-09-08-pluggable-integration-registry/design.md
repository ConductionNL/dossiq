# Design: pluggable-integration-registry

## Context

The installed `@conduction/nextcloud-vue` dist validates pages of
`type: "settings"` with `config.sections[]`, each section exactly one of
`fields`, `component` or `widgets`, and a widget of `type: "component"`
naming a `componentName` (REQ-MSO-6 in the dist's validator). `CnSettingsPage`
renders them. `CnIntegrationCard` exists in the dist, but it is the card
around an OpenRegister *leaf* widget (`integrationId`, `surface`, `chromeless`),
not a row with a connection status and a settings link: the card placement
row A34 imagines does not exist. `CnLeafDependencySettings` exists and
renders one row per app dependency with its installed state, which is the
nearest sibling shape. Dossiq's gear already lifts admin ids into the
settings foldout through `settingsSection` in `src/menu-layout.json`
(Tenants, Map layers, LHS, Committees, Substitutions), all `index` or
`custom` pages; `custom` is closed by the ratchet. The Nextcloud admin page
`lib/Settings/AdminSettings.php` renders `AdminRoot.vue` with seventeen
sections and provides `version`, `consultationSettings` and
`mandaatSettings` as initial state. `StufController::enrichEndpointWithHealth`
computes StUF health per endpoint; `EmailTemplateController::testImap` probes
the shared mailbox.

ADR-032 kind: **config**, with one imperative edge: two existing probes gain
a write of their result. ADR-079 rule 1 makes an in-app settings page that
*carries* app-level configuration a defect; this page carries none. It shows
status and links out to the configuration.

## Goals / Non-Goals

**Goals:**

- One page under the gear shows every external connection and its status.
- Each card links to the section that configures the connection.
- A connection with no implementation says so.

**Non-Goals:**

- Moving the configuration forms out of `/settings/admin/dossiq`. ADR-079
  keeps them there; the follow-up that shrinks the admin page waits on
  every section having a home.
- A Plugin configureren wizard (GZAC). Adding a connection is adding a
  section, which is a code change, not an admin action.
- Probing every connection. Only StUF and the mailbox have a probe today;
  the rest show what the admin saved, not what the network answered.
- The OpenRegister registry itself (openregister#1307). When it lands, the
  `dossiqIntegration` rows become a view over it.

## Decisions

### D1: the connections are objects of a config schema `dossiqIntegration`

Schema `dossiqIntegration` in `lib/Settings/dossiq_register.json`, version
1.0.0, with `key` (string, required, one of `zgw`, `stuf`, `kcc`, `dmn`,
`mailbox`, `store`, `financial`, `brp`, `kvk`, `pdok`), `title` (string,
required), `description` (string), `status` (enum `configured`,
`unconfigured`, `unavailable`, `error`, default `unconfigured`),
`statusMessage` (string), `checkedAt` (date-time), `settingsUrl` (uri, the
admin section anchor) and `order` (integer). English identifiers per D13.
The seed in `lib/Settings/register.d/96-integrations.json` holds the ten
rows with the status the baseline supports: `unconfigured` for ZGW, StUF,
KCC, DMN, mailbox, Store, financial and PDOK; `unavailable` for BRP and KvK
with the message "Specified, not built yet". A seed row never claims
`configured`.

### D2: the page is `type: "settings"` with one component section

Page `Integrations`, route `/settings/integrations`, `permission: admin`,
title Integrations, icon `PowerPlugOutline`, `config.sections`: one section
Connections whose `widgets` holds an `object-list` over `dossiqIntegration`
sorted on `order`, columns `title`, `status` (formatter `integrationStatus`,
a badge with the four states), `statusMessage`, `checkedAt`, and a row
action Open settings that navigates to the row's `settingsUrl`. No
`rowRoute` and no detail page: a connection is not a record you open. A
second section Required apps mounts `CnLeafDependencySettings` through a
`type: "component"` widget with `appId: "dossiq"` so the page also shows
which apps the connections need. Menu entry `IntegrationsMenu` in
`settingsSection` after Substitutions.

If the settings section cannot host an `object-list` widget in the installed
dist, the interim is page `Integrations` of type `index` over
`dossiqIntegration` with the same columns, `showViewAction: false` and the
same row action, and the Required apps section waits. Either way no
`custom` page.

### D3: the two probes write their result

`StufController::enrichEndpointWithHealth` and
`EmailTemplateController::testImap` call a small
`IntegrationStatusService::record(key, status, message)` that updates the
matching `dossiqIntegration` object (`status`, `statusMessage`, `checkedAt`)
through `ObjectService` with `_rbac: false`. Saving a section's settings
without a probe (KCC, Store, financial, DMN, ZGW, PDOK) records
`configured` when the required fields are filled and `unconfigured` when
they are cleared, from the same save handlers. This is the only PHP in the
change.

### D4: the admin page gains anchors and keeps its sections

Each section in `AdminRoot.vue` gets `id="section-<key>"` so `settingsUrl`
is `/settings/admin/dossiq#section-stuf`. Nothing moves in this change.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | reason |
|---|---|---|
| The page, the cards, the menu entry | declarative, manifest entries | `settings` pages and `object-list` widgets exist. |
| The ten rows | declarative, a schema and a seed | The list is data. |
| The status of StUF and the mailbox | imperative, two probes write an object | A network probe is not expressible in a manifest. |

## Seed Data

- `lib/Settings/register.d/96-integrations.json`: ten `dossiqIntegration`
  rows per D1, ordered as placement.md lists them.

## Risks / Trade-offs

- A seeded `unconfigured` lies for a connection an admin configured before
  this change. Accepted: the first save after upgrade corrects it, and the
  card's message says "Not checked yet" until then.
- `formatter: integrationStatus` may not be accepted by the object list in
  a settings section. Fallback: the raw enum value, still truthful.
- Two connections (DMN, KCC) are moving to OpenRegister in other changes.
  Their `settingsUrl` changes then; the seed is the only place to edit.
