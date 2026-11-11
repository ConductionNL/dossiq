/**
 * Golden test for validateTaskTransition: the answer for every pair of
 * statuses, recorded before the function was split into steps.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { describe, expect, it } from 'vitest'
import { validateTaskCreate, validateTaskTransition, validateTaskUpdate } from '../../src/utils/taskValidation.js'

const STATUSES = ['', 'available', 'active', 'completed', 'terminated', 'disabled', 'onbekend']

describe('validateTaskTransition', () => {
	it('answers every pair of statuses as before', () => {
		const answers = {}
		for (const from of STATUSES) {
			for (const to of STATUSES) {
				answers[`${from || '-'} -> ${to || '-'}`] = validateTaskTransition(from, to)
			}
		}
		answers['null -> active'] = validateTaskTransition(null, 'active')
		expect(answers).toMatchSnapshot()
	})
	it('answers the create and update forms as before', () => {
		const forms = [{}, { title: ' ' }, { title: 'Bel terug' }, { title: 'Bel terug', case: 'case-1' }, { case: 'case-1' }]
		expect({
			create: forms.map((form) => validateTaskCreate(form)),
			update: forms.map((form) => validateTaskUpdate(form)),
		}).toMatchSnapshot()
	})
})
