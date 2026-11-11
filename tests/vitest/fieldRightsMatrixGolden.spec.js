/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation golden for `fieldRightsMatrix` (src/utils/fieldRightsMatrix.js):
 * every cell state (hidden, readOnly, required, edit) per role column, the
 * column labels and the row order, recorded on the code before the split.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { describe, expect, it } from 'vitest'
import { fieldRightsMatrix } from '../../src/utils/fieldRightsMatrix.js'

const STATUSES = [
	{ id: 's1', name: 'Ontvangen', fieldRules: [{ rule: 'required', fields: ['amount', ' '] }, { rule: 'hidden', field: 'x' }] },
	{ uuid: 's2', name: { nl: 'In behandeling', en: 'In progress' }, fieldRules: [{ rule: 'required', field: 'reason' }] },
	{ id: 's3', name: 'Besluit' },
	'not a status',
]

const PROPERTIES = [
	{ name: ' amount ', title: { nl: 'Bedrag', en: 'Amount' } },
	{ name: 'reason', title: '' },
	{ name: 'amount', title: 'Duplicate' },
	{ name: 'decision', title: 'Besluit', requiredAtStatus: { id: 's3' } },
	{ name: 'note', title: 'Notitie', requiredAtStatus: 'unknown' },
	{ title: 'No name' },
	{ name: 'secret', title: 'Geheim' },
]

const RULES = [
	{ rule: 'hidden', field: 'secret', groups: ['intake', 'intake', ' '], statuses: ['s1'] },
	{ rule: 'readOnly', field: ' amount ', groups: ['intake'], statuses: ['s2', 's9'] },
	{ rule: 'readOnly', field: 'reason', groups: ['legal'] },
	{ rule: 'hidden', field: 'reason', groups: ['legal'] },
	{ rule: 'readOnly', field: 'unlisted', groups: ['audit'], statuses: ['s3'] },
	{ rule: 'editable', field: 'amount', groups: ['intake'] },
	{ rule: 'hidden', field: '', groups: ['intake'] },
	{ rule: 'hidden', field: 'secret', groups: [] },
	null,
]

describe('fieldRightsMatrix golden', () => {
	it('draws every column and cell', () => {
		expect(fieldRightsMatrix({
			rules: RULES,
			statuses: STATUSES,
			properties: PROPERTIES,
			roleNames: { intake: ['Intaker', 'Balie'], legal: [] },
		})).toMatchSnapshot()
	})

	it('draws nothing without rules or requirements', () => {
		expect(fieldRightsMatrix()).toEqual({ columns: [], rows: [] })
		expect(fieldRightsMatrix({ rules: 'x', properties: PROPERTIES })).toEqual({ columns: [], rows: [] })
	})

	it('lists required fields without columns when no role rule exists', () => {
		expect(fieldRightsMatrix({ statuses: STATUSES, properties: PROPERTIES })).toMatchSnapshot()
	})
})
