# Design: simple-structure-profile

## D-1 A profile is a layout file, chosen by one setting

| profile | file | when |
| --- | --- | --- |
| `simple` | `src/menu-layout.simple.json` | default, and any stored value that is not `full` |
| `full` | `src/menu-layout.json` | `menu_structure = full` |

`src/main.js` reads `menu_structure` from initial state and hands the chosen
file to `buildProfiledManifest()`. Initial state and not an HTTP read: the menu
is built before the first render, and a menu that arrives later rebuilds in
front of the reader.

Only the exact word `full` selects the full structure. The default has to be
the answer whenever the setting does not clearly say otherwise. PHP
(`MenuStructure::normalise`) and JavaScript (`resolveStructureProfile`) hold the
same rule, and `MenuStructureTest` fails when their words differ.

## D-2 What a profile file may hold

The four standard keys go to the library's `buildManifest` as they are:
`relocations`, `removals`, `settingsSection`, `integrationsSection`.

Two profile keys are applied by `src/utils/structureProfile.js`:

- `menu`: entries merged before the manifest's menu. An entry with only `id`
  and `order` keeps the manifest's label, icon and route and takes the order
  written in the profile. An entry the manifest does not know is added.
- `pages`: overlays by page id. `config` replaces the named config keys.
  `configAppend` appends items to a list in the config. An overlay naming a
  page that does not exist is skipped and logged.

A file without the two profile keys builds exactly what `buildManifest` builds.
That is the full profile, and a spec asserts the equality.

## D-3 The simple menu

| caption | entry | menu id | page |
| --- | --- | --- | --- |
| Start | Dashboard | `Dashboard` | `Dashboard` |
| Start | My work | `WorkGroup` | `MyWorkHome` |
| Start | Team queue | `Queue` | `Queue` |
| Cases | All cases | `Cases` | `Cases` |
| Cases | Board | `WorkflowBoard` | `WorkflowBoard` |
| Cases | Tasks | `Tasks` | `Tasks` |
| Cases | Woo requests | `WooRequestsMenu` | `Cases?caseType=<woo>` |
| Relations | Contacts | `Contacts` | `Contacts` |
| Relations | Organisations | `OrganisationsMenu` | `Organisations` |

Dutch labels: Start, Zaken, Relaties; Dashboard, Mijn werk, Wachtrij, Alle
zaken, Werkbord, Taken, Woo-verzoeken, Contacten, Organisaties.

Woo requests is the cases list narrowed to the Woo request case type. Its uuid
is fixed by `lib/Settings/register.d/81-woo-verzoek.json`, so the link is the
same on every instance. The cases list already reads `?caseType=` from the
address through its folder sidebar.

## D-4 Where the rest goes

| entry | simple profile |
| --- | --- |
| Your queue | link on My work |
| Assigned to me | link on My work |
| Close out your day | link on My work and on the dashboard |
| Deleted cases | settings |
| Objects | settings |
| Mail intake log | settings |
| Reports, Documentation, Store, Features & roadmap | footer, unchanged |
| 13 settings entries, 3 integrations | unchanged |

The links are `open-page` header actions, appended by the profile's page
overlays. They are in the simple profile only. A later change turns Your queue
and Assigned to me into views with counts on My work, once the library version
that carries counts is pinned.

The no-loss rule (ADR-044) is held by `tests/vitest/structureProfile.spec.js`:
every entry of the full menu is in the simple menu or its settings, or a page
the simple menu opens links to its route. gate-53 reads `src/menu-layout.json`
only, so it does not see the simple file.

## D-5 The archive gate

Root cause. The seven actions were gated on
`{ field: "@self.archived", op: "eq", value: null }`. OpenRegister leaves the
`archived` key off `@self` until a case is archived: a live, working case
answered 24 keys on 5 October 2026 and `archived` was not one of them. The
library reads the path as `undefined`, and `eq` holds when the two sides are
identical or when their string forms match. `undefined` is not `null`, and
"undefined" is not "null". So the actions were hidden on every working case.

Fix. `op: "empty"` holds for undefined, null and the empty string. A present
marker is an object, which is never empty.

Why nothing caught it. The existing test read the declaration and compared it
with itself. The new test evaluates each gated action with the library's own
evaluator against a working case, a case whose marker is null and an archived
case, and keeps the old spelling as a control.
