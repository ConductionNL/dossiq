/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
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
 * `page.goto()` prefix them with `/index.php/apps/procest`.
 */

/** `src/views/CasesOnMapView.vue` — the Cases map view (manifest page `CaseMap`). */
export const CasesOnMapView = '/map'

/** `src/views/MyWorkCards.vue` — the personal workload cards (manifest page `MyWork`). */
export const MyWorkCards = '/my-work'


/** `src/views/workflow-board/WorkflowBoard.vue` — the kanban status board. */
export const WorkflowBoard = '/workflow-board'

/**
 * `src/views/settings/SubstitutionSettings.vue` — self-service vervanging.
 * Retired as an app page by page-topology-cleanup (B4); it is now a PERSONAL
 * SETTING, so this is an absolute Nextcloud settings path, not an app route.
 */
export const SubstitutionPersonalSettings = '/settings/user/procest'

/** `src/views/admin/SubstitutionAdmin.vue` — the coordinator substitution console (manifest page `SubstitutionAdmin`). */
export const SubstitutionAdmin = '/substitution-admin'

/** Manifest `type: "dashboard"` page — bottleneck analysis; heading comes from the page title, widgets from `src/views/processMining/`. */
export const ProcessMiningDashboard = '/process-mining'

/**
 * SaaS tenant onboarding. Retired as an app page by page-topology-cleanup (B3);
 * it is now a section inside the ADMIN settings surface.
 */
export const TenantOnboardingAdminSettings = '/settings/admin/procest'

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

/**
 * `src/views/public/ExternalConsultationResponsePage.vue` — the token-addressed
 * advice-response surface for an external advisory body (manifest page
 * `ExternalConsultationResponse`, declared in
 * `src/manifest.d/consultation-public.json`).
 *
 * The token below is 44 characters, so it passes the controller's minimum-length
 * check and reaches the real `secureToken` lookup rather than short-circuiting
 * on length — and no consultation carries it, so
 * `consultationPublic#publicResponseGet` answers a uniform 404 and the page
 * lands in its `loadError` branch deterministically, with no fixture to seed.
 * Measured against a running instance (procest 0.3.9, 2026-08-17): a 44-char
 * unknown token and a 5-char token both answer
 * `404 {"error":"Invalid or expired token"}`, while a token that a seeded
 * consultation really carries answers 200 with that consultation — see
 * `workflows/external-consultation-response.spec.ts`, which asserts both
 * directions.
 */
export const ExternalConsultationResponsePage =
	'/public/consultations/e2eunresolvableconsultationtoken000000000000'
