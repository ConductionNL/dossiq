/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Overview cards as case type configuration: rows read the case, and the three
 * procedure case types declare their cards and leave the generic ones out.
 *
 * @spec openspec/changes/overview-sections-per-case-type/specs/case-page/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { resolveOverviewSections } from '../../src/utils/caseOverviewSections.js'

const read = (...parts) => JSON.parse(fs.readFileSync(path.join(__dirname, '../..', ...parts), 'utf8'))

describe('resolveOverviewSections', () => {
	const declared = [{
		id: 'hearing',
		title: 'Hearing',
		pillField: 'state',
		rows: [{ label: 'When', field: 'when' }, { label: 'Case', field: 'title' }, { label: 'Report', field: 'report' }, { label: 'Fixed', value: 'Text' }],
	}]

	it('reads the properties first, then the case, and shows a dash for nothing', () => {
		const [section] = resolveOverviewSections(declared, { title: 'A case', properties: { when: '5 nov', state: 'Planned' } })

		expect(section.pill).toBe('Planned')
		expect(section.rows.map((row) => row.value)).toEqual(['5 nov', 'A case', '—', 'Text'])
	})

	it('draws nothing without a case or a list, and leaves out a section without a title', () => {
		expect(resolveOverviewSections(declared, null)).toEqual([])
		expect(resolveOverviewSections(null, {})).toEqual([])
		expect(resolveOverviewSections([{ id: 'x' }], {})).toEqual([])
	})

	it('shows no object as a value', () => {
		const [section] = resolveOverviewSections([{ id: 'a', title: 'A', rows: [{ label: 'L', field: 'o' }] }], { o: { secret: 1 } })

		expect(section.rows[0].value).toBe('—')
	})
})

describe('the procedure case types', () => {
	const types = [
		read('lib', 'Settings', 'packages', 'subsidie', 'case-type.json').caseType,
		read('lib', 'Settings', 'packages', 'aanbesteding', 'case-type.json').caseType,
		read('lib', 'Settings', 'dossiq_register.json').components.objects.find((row) => row.title === 'Klacht behandeling'),
	]

	it('declare their sections and leave out the generic priority and locations cards', () => {
		for (const type of types) {
			expect(type.overviewSections.length).toBeGreaterThan(0)
			expect(type.overviewCards).toContain('case-overview-sections')
			expect(type.overviewCards).not.toContain('case-priority-card')
			expect(type.overviewCards).not.toContain('case-locations-card')
		}
	})

	it('puts Voorschotten on the way to Beschikt, not after a closed status', () => {
		const pack = read('lib', 'Settings', 'packages', 'subsidie', 'case-type.json')
		const flow = read('lib', 'Settings', 'packages', 'subsidie', 'flows', 'subsidie.json')
		const status = (name) => pack.statusTypes.find((row) => row.name === name)
		const moves = JSON.parse(flow.transitions)

		expect(status('Voorschotten').isFinal).toBe(false)
		expect(moves.some((move) => move.fromStatus === status('In behandeling').id && move.toStatus === status('Voorschotten').id)).toBe(true)
		expect(moves.some((move) => move.fromStatus === status('Voorschotten').id && move.toStatus === status('Beschikt').id)).toBe(true)
		expect(moves.some((move) => move.fromStatus === status('Beschikt').id)).toBe(false)
	})
})
