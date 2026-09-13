/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Where a panel of the case page lives, now that the strip is folded.
 *
 * The strip carried fourteen tabs, one per panel, so every spec could write
 * `getByRole('tab', { name: /Parties/ })` and be done. Folding it means most
 * panels are a SECTION inside a tab instead, and a spec that keeps clicking
 * for a tab named `Parties` fails with a locator timeout that says nothing
 * about what changed.
 *
 * This is the one place that mapping lives, so the next fold moves one table
 * rather than nine spec files. It returns the SECTION locator, not the panel
 * root, because two collections now share a tab: an unscoped assertion inside
 * a merged tab can be satisfied by the wrong half of it, which is a test that
 * passes for the wrong reason.
 *
 * 🔴 AND `[aria-label="<widget id>"]` DOES NOT REACH A PANEL, which is the
 * other half of the same trap. `CnDetailPage` sets that label from the
 * manifest id only on the widgets it lays out itself, and `case-panels`
 * renders its children instead of the grid. So a widget that moved into the
 * strip keeps its id in the manifest and loses the attribute in the DOM, and a
 * locator naming it matches nothing while the panel renders perfectly.
 * `case-core` made that move: it is the strip's first tab, `Data`.
 *
 * The panels are also LAZY. A panel does not mount, and therefore does not
 * query, until its tab has been opened once, so the click is part of reaching
 * the content rather than a convenience.
 *
 * Scoping to the strip matters for a third reason: the app sidebar's own
 * panels carry `role="tabpanel"` too and hide with `aria-hidden` rather than
 * `hidden`, so an unscoped query matches a hidden sidebar panel as readily as
 * this one.
 */

import type { Locator, Page } from '@playwright/test'

import { expect } from '@playwright/test'
import { dismissSupportDialog } from './nav.ts'

/**
 * Every panel of the case page: which tab opens it, and the section it is.
 *
 * `section` is null for a tab that still holds exactly one widget, where the
 * panel body IS the tab body.
 */
export const CASE_PANELS = {
	data: { tab: /^(Data|Gegevens)$/, section: null },
	documents: { tab: /^Documents$/, section: 'case-section-case-documents' },
	files: { tab: /^Documents$/, section: 'case-section-case-files' },
	parties: { tab: /^People$/, section: 'case-section-case-roles' },
	communication: {
		tab: /^Communication$/,
		section: 'case-section-case-communication',
	},
	tasks: { tab: /^Work$/, section: 'case-section-case-tasks' },
	appointments: { tab: /^Work$/, section: 'case-section-case-calendar' },
	relatedCases: { tab: /^Related$/, section: 'case-section-case-related' },
	subCases: { tab: /^Related$/, section: 'case-section-case-sub-cases' },
	objects: {
		tab: /^Objects and locations$/,
		section: 'case-section-case-objects',
	},
	locations: {
		tab: /^Objects and locations$/,
		section: 'case-section-case-locaties',
	},
} as const

export type CasePanel = keyof typeof CASE_PANELS

/** The tab strip on the case page. */
export function caseStrip(page: Page): Locator {
	return page.locator('.cn-tabs-widget')
}

/**
 * Open the tab a panel lives on, and return that panel.
 *
 * @param page The page, already on a case.
 * @param panel Which panel to open.
 * @return The section locator, or the open tab panel when the tab holds one widget.
 */
export async function openCasePanel(page: Page, panel: CasePanel): Promise<Locator> {
	// The support dialog and the first-run wizard each mount a modal mask that
	// swallows every click on the app behind it, and a swallowed click reports
	// as a locator timeout, which reads as a missing tab rather than a covered
	// one. This helper's whole job is to reach a panel, so getting past the
	// masks is part of it.
	await dismissSupportDialog(page)

	const { tab, section } = CASE_PANELS[panel]
	const strip = caseStrip(page)
	await expect(strip).toBeVisible({ timeout: 30_000 })
	await strip.getByRole('tab', { name: tab }).click()

	// `.cn-tabs__content >` is development's scoping (#2001) and it matters
	// more here than it did there: a folded tab can hold a widget that draws
	// its own tabpanel, so an unscoped query inside the strip is ambiguous.
	const open = strip.locator('.cn-tabs__content > [role="tabpanel"]:not([hidden])')
	if (!section) return open
	const body = open.locator(`[data-testid="${section}"]`)
	await expect(
		body,
		`the ${panel} section did not render inside its tab`,
	).toBeVisible({ timeout: 30_000 })
	return body
}
