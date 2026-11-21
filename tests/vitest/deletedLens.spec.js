/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Cases are archival, so there is no deleted lens.
 *
 * Ruben decided on 17 November 2026 that a case is never deleted: it is
 * withdrawn or closed through the lifecycle menu, and it leaves the register
 * only through an archival destruction list. The Deleted cases page that
 * `case-recycle-window` added is therefore gone, and nothing on the case
 * list pretends to show deleted cases either.
 *
 * @spec openspec/changes/cases-are-archival-no-delete/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const manifest = JSON.parse(read('src', 'manifest.json'))
const register = JSON.parse(read('lib', 'Settings', 'dossiq_register.json'))
const layouts = ['menu-layout.json', 'menu-layout.simple.json'].map((file) => JSON.parse(read('src', file)))
const lifecycleMenu = read('src', 'dialogs', 'CaseLifecycleMenuDialog.vue')

/**
 * Every menu entry, nested ones included.
 *
 * @param {Array<object>} items The menu.
 * @return {Array<object>} The entries.
 */
function flat(items) {
	return (items || []).flatMap((item) => [item, ...flat(item.children)])
}

describe('cases are archival', () => {
	it('has no page over the deleted-cases endpoint', () => {
		expect(manifest.pages.find((page) => page.id === 'CasesDeleted')).toBeUndefined()
		expect(manifest.pages.find((page) => page.route === '/cases/deleted')).toBeUndefined()
		expect(JSON.stringify(manifest.pages)).not.toContain('/api/cases/deleted')
	})

	it('has no menu entry for deleted cases, in the manifest or in either layout', () => {
		expect(flat(manifest.menu).find((item) => item.route === 'CasesDeleted' || item.id === 'CasesDeletedMenu')).toBeUndefined()
		for (const layout of layouts) {
			expect(JSON.stringify(layout)).not.toContain('"CasesDeletedMenu"')
		}
	})

	it('offers no delete of a case anywhere in the manifest', () => {
		expect(JSON.stringify(manifest)).not.toContain('/api/case/@objectId/delete')
		expect(JSON.stringify(manifest)).not.toContain('/api/case/@objectId/destroy')
	})

	it('ends a case by withdrawing or closing it in the lifecycle menu', () => {
		expect(lifecycleMenu).toContain("['abort', () => t('dossiq', 'Break off')]")
		expect(lifecycleMenu).toMatch(/\['finish', \(\) => t\('dossiq', /)
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

describe('the case type keeps its two clocks', () => {
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
