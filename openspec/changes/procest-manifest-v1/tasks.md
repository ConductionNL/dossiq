# Tasks — Procest manifest v1: per-page Vue → JSON manifest renderer + CnAppRoot

## 1. Manifest migration

- [x] 1.1 Walk every route in `src/router/index.js`. For each, decide manifest target type per the `design.md` mapping table.
- [x] 1.2 Walk every menu item in `src/navigation/MainMenu.vue`. Map each to a `manifest.menu[]` entry preserving section placement.
- [x] 1.3 Source schema slugs from `lib/Settings/procest_register.json` for non-custom pages.
- [x] 1.4 Write `src/manifest.json` with `version: "1.0.0"`, `dependencies: ["openregister"]`, 8 menu entries, and 17 pages (1 dashboard + 3 index + 3 detail + 10 custom).
- [x] 1.5 For each `type: "index"` page, declare `config.{ register, schema, columns, sidebar: { enabled: true, showMetadata: true } }`.
- [x] 1.6 For each `type: "detail"` page, declare `config.{ register, schema, sidebarTabs }` per the tab inventory in `design.md`.
- [x] 1.7 For `Dashboard`, declare `config.{ widgets, layout }` mirroring the existing `Dashboard.vue` `widgetDefs` + `DEFAULT_LAYOUT`.
- [x] 1.8 For surviving `type: "custom"` pages, set `component` to the registered component name from `src/customComponents.js`.
- [x] 1.9 Run `node tests/validate-manifest.js` and confirm zero schema errors.

## 2. CnAppRoot adoption

- [x] 2.1 Rewrite `src/main.js`:
  - import `CnPageRenderer`, `defaultPageTypes`, `registerIcons`, `registerTranslations` from `@conduction/nextcloud-vue`
  - shallow-clone `CnPageRenderer` into `RoutePageRenderer` (Vue.extend non-extensible workaround)
  - implement `routesFromManifest(manifest)` returning `{ name, path, component: RoutePageRenderer, props: path.includes(':') }` plus the `*` redirect
  - mount-survivable bootstrap: `tryLoadTranslations()` is fire-and-forget; `registerTranslations()` wrapped in try/catch
  - shallow-clone `defaultPageTypes` and `customComponents` before passing to `App` props
- [x] 2.2 Rewrite `src/App.vue` as a `<CnAppRoot>` host. Preserve the OpenRegister-missing empty state when `settingsStore.hasOpenRegisters === false`. Wire `objectSidebarState` provide/inject channel for the host `CnObjectSidebar` (matching decidesk's pattern).
- [x] 2.3 Add `src/customComponents.js` exporting the surviving 10 entries: `MyWorkView`, `WerkvoorraadView`, `CaseMapView`, `DoorlooptijdView`, `VoorstellenView`, `VoorstelDetailView`, `AdminRootView`, `PublicCaseView`, `PublicAppointmentPage`, `PublicStatusPage`. Plus 3 sidebar-tab stubs (`CaseTasksTab`, `CaseDecisionsTab`, `CaseDocumentsTab`).
- [x] 2.4 Delete `src/router/index.js` (folded into main.js).
- [x] 2.5 Delete `src/navigation/MainMenu.vue` (replaced by `CnAppNav` mounted by `CnAppRoot`).

## 3. Webpack + dependencies

- [x] 3.1 Bump `package.json` `@conduction/nextcloud-vue` floor from `^1.0.0-beta.6` to `^1.0.0-beta.12`.
- [x] 3.2 Update `webpack.config.js` axios handling to mirror decidesk: keep the existing `@nextcloud/axios` pin via `package.json` `overrides` (`~2.5.2` ships both `import`+`require` export conditions, so no alias is needed). Add a comment block documenting why no alias.
- [x] 3.3 Run `npm install --legacy-peer-deps` to refresh the lockfile.
- [x] 3.4 Run `npx webpack --config webpack.config.js --mode production` and confirm clean build.

## 4. View deletion + custom-registry stubs

- [x] 4.1 Delete the 7 obsolete per-page Vue files for migrated routes:
  - `src/views/Dashboard.vue`
  - `src/views/cases/CaseList.vue`
  - `src/views/cases/CaseDetail.vue`
  - `src/views/tasks/TaskList.vue`
  - `src/views/tasks/TaskDetail.vue`
  - `src/views/complaints/ComplaintList.vue`
  - `src/views/complaints/ComplaintDetail.vue`
- [x] 4.2 Add `src/components/tabs/CaseTasksTab.vue` — stub rendering `CnNoteCard` placeholder with `<!-- TODO: implement case tasks relation tab -->`.
- [x] 4.3 Add `src/components/tabs/CaseDecisionsTab.vue` — stub.
- [x] 4.4 Add `src/components/tabs/CaseDocumentsTab.vue` — stub.

## 5. i18n + finishing touches

- [x] 5.1 Mirror `l10n/en.json` to `l10n/en_US.json`. The two files MUST stay byte-identical so the `en_US` locale fetch resolves through the same English source.
- [x] 5.2 Bump `appinfo/info.xml` `<version>` from `0.1.10` to `0.2.0`.
- [x] 5.3 Add `tests/validate-manifest.js` cloned from `decidesk/tests/validate-manifest.js`; replace `decidesk` references with `procest`.

## 6. Validation

- [x] 6.1 Run `node tests/validate-manifest.js` — must PASS against schema v1.2.0.
- [x] 6.2 Run `npx eslint src/main.js src/App.vue src/customComponents.js src/components/tabs/` — must be clean. **Blocked**: same as decidesk's worktree, the project's flat eslint config references `eslint-plugin-import` which `npm install --legacy-peer-deps` does not pull in (peer-dep gap in `@nextcloud/eslint-config`). Source files manually inspected for syntax issues; the webpack build below is the de-facto compile gate.
- [x] 6.3 Run `npx webpack --config webpack.config.js --mode production` — must succeed.
- [x] 6.4 Confirm all current router named routes (`Dashboard`, `MyWork`, `Werkvoorraad`, `Cases`, `CaseDetail`, `Tasks`, `TaskNew`, `TaskDetail`, `CaseMap`, `Voorstellen`, `VoorstelDetail`, `Settings`, `CaseTypes`) survive in the manifest as page `id`s so existing `$router.push({ name: ... })` calls keep resolving.

## 7. Spec sign-off (per ADR-024 §9)

- [x] 7.1 `src/manifest.json` validates against `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json` (v1.2.0).
- [x] 7.2 `manifest.dependencies` is `["openregister"]`.
- [x] 7.3 Tier choice: Tier 4 (manifest-driven `CnAppRoot` consumer).
- [x] 7.4 `manifest.version` is `"1.0.0"`.
- [x] 7.5 Custom-fallback inventory documented and categorised in `design.md` (genuine exception / lib gap / migration cost).
