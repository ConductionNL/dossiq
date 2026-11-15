/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Route constants NAMED AFTER THE COMPONENT THAT RENDERS THEM.
 *
 * Why the identifier matters
 * -------------------------
 * A spec that navigates to `/my-work` really does drive
 * `src/views/MyWorkCards.vue`, but nothing in the spec SAYS so,
 * so neither a reader nor a tool can tell which screen the test covers. The
 * link used to be written in a comment — and a comment is not a reference:
 * hydra gate-26 (visual-coverage) reads the e2e corpus through
 * `source_scope.js_comment_mask`, which blanks comments and keeps string
 * literals, precisely so "we still owe a baseline for X" cannot satisfy the
 * check that asks whether X is covered (.github#358).
 *
 * Binding the route to a constant whose IDENTIFIER is the component's file
 * stem fixes both halves at once: the spec reads as "navigate to the
 * MyWorkCards screen", and the reference survives comment-masking
 * because it is executable code the spec actually evaluates.
 *
 * Scope: only screens that an existing spec in this suite genuinely drives.
 * Adding a constant here for a screen no spec visits would be a declaration
 * nobody reads — the exact failure mode the masking above exists to prevent.
 * Add the entry when you add the test, not before. Screens this suite does not
 * yet drive are deliberately ABSENT here rather than listed and unused.
 *
 * Every route below was MEASURED against a running instance before its constant
 * was added — navigate, read the rendered headings, confirm the screen is the
 * one the constant names. The probe discriminates: an unrouted path falls back
 * to the Dashboard, and a routed page whose component is not registered renders
 * the manifest renderer's "This page is empty" placeholder instead. Both are
 * distinguishable from a real render, so "it rendered" is a finding rather than
 * an assumption.
 *
 * Routes are app-relative; the app is HISTORY-mode, so `navToRoute()` /
 * `page.goto()` prefix them with `/index.php/apps/dossiq`.
 */

/**
 * A default view of the Cases index (manifest page `Cases`, `defaultSavedViews`):
 * `my-work`, `team-queue`, `data-subject-requests`, `woo-requests`.
 *
 * @param slug The view's slug.
 * @return The app-relative route that opens the view.
 */
export function CasesSavedView(slug: string): string {
	return `/cases?savedView=default:${slug}`
}

/**
 * Whether nextcloud-vue renders a view's page config `overrides` (card
 * layout, columns, slots, header, tabs; library batch 18). The tests that
 * read what a view restores (openspec/changes/archive/2026-10-30-lists-are-views/restore.md)
 * are fixme while this is false.
 */
export const VIEW_OVERRIDES_APPLIED = true

/**
 * `src/views/settings/SubstitutionSettings.vue` — self-service vervanging.
 * Retired as an app page by page-topology-cleanup (B4); it is a PERSONAL
 * SETTING, a section of the in-app user settings dialog. App.vue opens the
 * dialog on this hash (dossiq has no Nextcloud personal settings page since
 * 11 Oct 2026).
 */
export const SubstitutionPersonalSettings = '/apps/dossiq/#user-settings'

/**
 * `src/views/admin/SubstitutionAdmin.vue` — the coordinator substitution console,
 * the Substitution tab of the admin settings (its own page `/substitution-admin`
 * retired on 20 Oct 2026 and redirects here). Prefix with `/index.php` only.
 */
export const SubstitutionAdmin = '/settings/admin/dossiq?tab=substitution'

/** Manifest `type: "dashboard"` page — bottleneck analysis; heading comes from the page title, widgets are library charts, stats and tables over `/api/reports/process-mining`. */
export const ProcessMiningDashboard = '/process-mining'

/**
 * SaaS tenant onboarding. Retired as an app page by page-topology-cleanup (B3);
 * it is now a section inside the ADMIN settings surface.
 */
// The Nextcloud admin-settings SECTION id, which follows the app id — not the
// OpenRegister register slug, which is frozen at `procest` and must not move
// with it (see REGISTER in fixtures.ts).
export const TenantOnboardingAdminSettings = '/settings/admin/dossiq'

/** Manifest `type: "dashboard"` page — AWB termijnbewaking KPIs + quarterly/annual reports; heading comes from the page title, widgets from `src/views/termijn/`. */
export const TermijnDashboard = '/termijn-dashboard'

/**
 * `src/views/public/PublicStatusPage.vue` — the citizen "track your case" page.
 *
 * Token-addressed. The token below is deliberately one that cannot resolve:
 * OpenRegister answers an unknown / revoked / expired / RBAC-denied token with
 * a uniform 404 (see the component's `loadStatus()` docblock), so the page
 * lands in its `error` branch deterministically, with no fixture to seed.
 */
export const PublicStatusPage = '/public/status/e2e-unresolvable-token'

/**
 * `src/views/public/PublicAppointmentPage.vue` — the citizen appointment page.
 *
 * Same shape as PublicStatusPage: an unresolvable token makes
 * `publicAppointment#view` 404, which is the page's `error` branch.
 */
export const PublicAppointmentPage = '/public/appointments/e2e-unresolvable-token'

/**
 * `src/views/public/PublicFederatedTransferPage.vue` — the remote-organisation
 * accept/reject surface for a federated case transfer.
 *
 * This one takes no fetch on mount at all — the component's own header records
 * that no GET endpoint exists to pre-load transfer details, so it presents the
 * action rather than a data view. Its heading is therefore independent of every
 * backend, and the token/id in the path are never dereferenced by the render.
 */
export const PublicFederatedTransferPage =
	'/public/federation/transfer/e2e-unresolvable-token/e2e-transfer'
