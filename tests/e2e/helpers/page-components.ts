/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Route constants NAMED AFTER THE COMPONENT THAT RENDERS THEM.
 *
 * Why the identifier matters
 * -------------------------
 * A spec that navigates to `/verwerkingen` really does drive
 * `src/views/admin/VerwerkingenOverview.vue`, but nothing in the spec SAYS so,
 * so neither a reader nor a tool can tell which screen the test covers. The
 * link used to be written in a comment — and a comment is not a reference:
 * hydra gate-26 (visual-coverage) reads the e2e corpus through
 * `source_scope.js_comment_mask`, which blanks comments and keeps string
 * literals, precisely so "we still owe a baseline for X" cannot satisfy the
 * check that asks whether X is covered (.github#358).
 *
 * Binding the route to a constant whose IDENTIFIER is the component's file
 * stem fixes both halves at once: the spec reads as "navigate to the
 * VerwerkingenOverview screen", and the reference survives comment-masking
 * because it is executable code the spec actually evaluates.
 *
 * Scope: only screens that an existing spec in this suite genuinely drives.
 * Adding a constant here for a screen no spec visits would be a declaration
 * nobody reads — the exact failure mode the masking above exists to prevent.
 * Add the entry when you add the test, not before. Screens this suite does not
 * yet drive are deliberately ABSENT here rather than listed and unused —
 * `/substitution` and `/substitution-admin` are the current example: they are
 * driven by handler-vervanging-waarneming.spec.ts and will get their constants
 * when that file is next touched (it is mid-flight in PR #863).
 *
 * Routes are app-relative; the app is HISTORY-mode, so `navToRoute()` /
 * `page.goto()` prefix them with `/index.php/apps/procest`.
 */

/** `src/views/CasesOnMapView.vue` — the Cases map view (manifest page `CaseMap`). */
export const CasesOnMapView = '/map'

/** `src/views/MyWorkCards.vue` — the personal workload cards (manifest page `MyWork`). */
export const MyWorkCards = '/my-work'

/** `src/views/admin/VerwerkingenOverview.vue` — the AVG processing-activity register. */
export const VerwerkingenOverview = '/verwerkingen'

/** `src/views/workflow-board/WorkflowBoard.vue` — the kanban status board. */
export const WorkflowBoard = '/workflow-board'
