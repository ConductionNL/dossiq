/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure presentation helpers in src/services/caseRelationApi.js
 * (relation-type labels, guard-reason → message mapping, type list). The
 * network functions are not exercised here (they need axios + a live route);
 * these tests pin the user-facing string mapping that the section/modal rely on.
 *
 * The global `t()` (NC translation) is stubbed to return the English source
 * string so output is deterministically assertable.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */

import { beforeAll, describe, expect, it } from 'vitest'

beforeAll(() => {
	globalThis.t = (app, text) => text
})

const importApi = async () => await import('../../src/utils/caseRelationHelpers.js')

describe('AARD_RELATIE_TYPES', () => {
	it('lists the RGBZ/ZRC relation types plus the symmetric one', async () => {
		const { AARD_RELATIE_TYPES } = await importApi()
		expect(AARD_RELATIE_TYPES).toEqual([
			'vervolg',
			'subject',
			'bijdrage',
			'samenhang',
		])
	})
})

describe('relationDisplayLabel', () => {
	it('reads displayLabel and nothing else', async () => {
		const { relationDisplayLabel } = await importApi()
		// The label pair resolved by OpenRegister. An implementation that chose
		// between label and inverseLabel itself, or recomputed either from the
		// type, could not answer the far half here.
		expect(
			relationDisplayLabel({
				aardRelatie: 'vervolg',
				label: 'vervolg op',
				inverseLabel: 'heeft vervolg',
				displayLabel: 'heeft vervolg',
				direction: 'incoming',
			}),
		).toBe('heeft vervolg')

		expect(
			relationDisplayLabel({
				aardRelatie: 'vervolg',
				label: 'vervolg op',
				inverseLabel: 'heeft vervolg',
				displayLabel: 'vervolg op',
				direction: 'outgoing',
			}),
		).toBe('vervolg op')
	})

	it('falls back to the type word for a row written before the schema declared one', async () => {
		const { relationDisplayLabel } = await importApi()
		expect(
			relationDisplayLabel({
				aardRelatie: 'bijdrage',
				displayLabel: null,
				legacy: true,
			}),
		).toBe('Contribution')
		expect(
			relationDisplayLabel({ aardRelatie: 'subject', displayLabel: '  ' }),
		).toBe('Subject')
	})
})

describe('relationSections', () => {
	it('groups the rows under the name each has from this case', async () => {
		const { relationSections } = await importApi()
		const sections = relationSections([
			{ caseId: 'b', title: 'Besluit', displayLabel: 'vervolg op' },
			{ caseId: 'c', title: 'Handhaving', displayLabel: 'heeft vervolg' },
			{ caseId: 'd', title: 'Toezicht', displayLabel: 'heeft vervolg' },
		])

		expect(sections.map((section) => section.label)).toEqual([
			'vervolg op',
			'heeft vervolg',
		])
		expect(sections[0].items).toEqual([{ id: 'b', label: 'Besluit' }])
		expect(sections[1].items.map((item) => item.id)).toEqual(['c', 'd'])
	})

	it('carries the clarification on the row and drops a row naming no case', async () => {
		const { relationSections } = await importApi()
		const sections = relationSections([
			{
				caseId: 'b',
				title: 'Besluit',
				displayLabel: 'gaat over',
				notes: 'Bezwaar',
			},
			{ caseId: '', title: 'Nothing', displayLabel: 'gaat over' },
		])

		expect(sections).toHaveLength(1)
		expect(sections[0].items).toEqual([{ id: 'b', label: 'Besluit (Bezwaar)' }])
	})

	it('answers nothing for a case with no relations', async () => {
		const { relationSections } = await importApi()
		expect(relationSections([])).toEqual([])
		expect(relationSections(undefined)).toEqual([])
	})
})

describe('relationTypeLabel', () => {
	it('maps each known type to a label', async () => {
		const { relationTypeLabel } = await importApi()
		expect(relationTypeLabel('vervolg')).toBe('Follow-up')
		expect(relationTypeLabel('subject')).toBe('Subject')
		expect(relationTypeLabel('bijdrage')).toBe('Contribution')
		expect(relationTypeLabel('samenhang')).toBe('Related')
	})

	it('falls back to the raw value for an unknown type', async () => {
		const { relationTypeLabel } = await importApi()
		expect(relationTypeLabel('mystery')).toBe('mystery')
	})
})

describe('relationErrorMessage', () => {
	it('maps each guard reason to a distinct message', async () => {
		const { relationErrorMessage } = await importApi()
		expect(relationErrorMessage('self_relation')).toMatch(/itself/i)
		expect(relationErrorMessage('duplicate')).toMatch(/already exists/i)
		expect(relationErrorMessage('hierarchy_overlap')).toMatch(/hierarchy/i)
		expect(relationErrorMessage('access_denied')).toMatch(/access/i)
		expect(relationErrorMessage('invalid_aard_relatie')).toMatch(
			/valid relation type/i,
		)
	})

	it('falls back to a generic message for an unknown reason', async () => {
		const { relationErrorMessage } = await importApi()
		expect(relationErrorMessage('whatever')).toMatch(/could not save/i)
	})
})
