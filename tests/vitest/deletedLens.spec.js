/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The deleted lens, and why it is not a chip.
 *
 * The obvious shape for this lens is a seventh quick filter beside All, Mine
 * and Closed. It cannot be one, and the failure would be silent: those chips
 * are filters on the objects endpoint, and that endpoint excludes soft-deleted
 * rows by design. A chip spelling `deleted: true` would get the empty set back
 * and render as a working lens over an empty trash.
 *
 * So the assertions here pin the two halves that make the lens real: the page
 * exists with a component behind it, and no chip on the case list pretends to
 * do the same job.
 *
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
const customComponents = fs.readFileSync(
	path.join(ROOT, 'src', 'customComponents.js'),
	'utf8',
)
const register = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'),
		'utf8',
	),
)

/** The deleted lens page as the manifest declares it. @return {object} The page. */
function deletedPage() {
	return manifest.pages.find((page) => page.id === 'CasesDeleted')
}

describe('the deleted lens', () => {
	it('is a page of its own, with a component behind it', () => {
		const page = deletedPage()

		expect(page).toBeTruthy()
		expect(page.route).toBe('/cases/deleted')
		expect(page.type).toBe('custom')
		expect(page.component).toBe('DeletedCasesView')
	})

	it('has its component registered, so the route renders something', () => {
		expect(customComponents).toContain('DeletedCasesView')
		expect(
			fs.existsSync(
				path.join(ROOT, 'src', 'views', 'cases', 'DeletedCasesView.vue'),
			),
		).toBe(true)
	})

	it('is reachable from the menu, with an icon that exists', () => {
		const entry = manifest.menu.find((item) => item.route === 'CasesDeleted')

		expect(entry).toBeTruthy()
		expect(entry.label).toBe('Deleted cases')
		// An icon that is not in src/icons.js renders nothing at all rather
		// than a fallback glyph, so the menu entry would be a blank row.
		expect(iconsSource).toContain(`\n\t${entry.icon},`)
	})

	it('shows the date each window ends, not a duration the reader computes', () => {
		const view = fs.readFileSync(
			path.join(ROOT, 'src', 'views', 'cases', 'DeletedCasesView.vue'),
			'utf8',
		)

		expect(view).toContain('windowEndsOn')
		expect(view).toContain('Recover until')
	})

	it('does not put a deleted chip on the case list, which would answer nothing', () => {
		const cases = manifest.pages.find((page) => page.id === 'Cases')
		const chips = cases.config.quickFilters.map((chip) => chip.label)

		expect(chips).not.toContain('Deleted')
		for (const chip of cases.config.quickFilters) {
			expect(Object.keys(chip.filter)).not.toContain('deleted')
			expect(Object.keys(chip.filter)).not.toContain('_deleted')
		}
	})
})

describe('the case type declares the destructive act', () => {
	it('names the destroying role and the recovery window', () => {
		const caseType = register.components.schemas.caseType.properties

		expect(caseType.destructionRole).toBeTruthy()
		expect(caseType.destructionRole.type).toBe('string')
		expect(caseType.recoveryWindowDays.type).toBe('integer')
		expect(caseType.recoveryWindowDays.minimum).toBe(1)
	})

	it('keeps the lawful-purpose clock beside the archive clock, not instead of it', () => {
		const caseSchema = register.components.schemas.case.properties

		expect(caseSchema.lawfulPurposeEndDate).toBeTruthy()
		expect(caseSchema.archiveActionDate).toBeTruthy()
		expect(caseSchema.lawfulPurposeEndDate.format).toBe('date')
		expect(caseSchema.lawfulPurposeEndDate.title).not.toBe(
			caseSchema.archiveActionDate.title,
		)
	})
})
