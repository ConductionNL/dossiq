/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Doorlooptijd case type choice is a declarative page filter on the
 * description line (DqDoorlooptijd "alle zaaktypen"), not a bespoke select in
 * the header's action slot. It still offers only the case types that declare
 * a processing deadline, writes `caseType` into the workspace the widgets
 * read, and clears back to every case type.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const page = manifest.pages.find((item) => item.id === 'Doorlooptijd')

describe('the Doorlooptijd case type filter', () => {
	it('is a clearable page filter on the description line', () => {
		const filter = page.config.pageFilters.find((pf) => pf.key === 'caseType')
		expect(filter).toMatchObject({ placement: 'description', clearable: true, placeholder: 'All case types', label: 'Filter by case type' })
		expect(filter.control).toBeUndefined()
	})

	it('offers only case types that declare a processing deadline', () => {
		const filter = page.config.pageFilters.find((pf) => pf.key === 'caseType')
		expect(filter.optionsFrom).toEqual({ register: 'dossiq', schema: 'caseType', filter: { processingDeadline_isnull: false }, labelField: 'title' })
	})

	it('feeds the widgets that read @workspace.caseType', () => {
		const reads = JSON.stringify(page.config.widgets).match(/@workspace\.caseType/g) || []
		expect(reads.length).toBeGreaterThan(0)
	})

	it('leaves the header action slot to the board\'s buttons', () => {
		expect(page.slots?.['header-actions']).toBeUndefined()
		expect(fs.existsSync(path.join(ROOT, 'src/views/doorlooptijd/widgets/DtCaseTypeFilter.vue'))).toBe(false)
	})
})
