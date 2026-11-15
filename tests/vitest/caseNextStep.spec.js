/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The step a case moves to next, read from its case type's statuses.
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003
 */

import { describe, expect, it } from 'vitest'
import { nextStepOf } from '../../src/utils/caseNextStep.js'

const STATUSES = [
	{ id: 'st-3', name: 'Besluit', order: 3 },
	{ id: 'st-1', name: 'Ontvangen', order: 1 },
	{ id: 'st-2', name: 'In behandeling', order: 2 },
]

describe('nextStepOf', () => {
	it('numbers the next status by its order', () => {
		expect(nextStepOf(STATUSES, 'st-2')).toEqual({ number: 3, name: 'Besluit' })
		expect(nextStepOf(STATUSES, 'st-1')).toEqual({ number: 2, name: 'In behandeling' })
	})

	it('answers null on the last status, an unknown status or no statuses', () => {
		expect(nextStepOf(STATUSES, 'st-3')).toBeNull()
		expect(nextStepOf(STATUSES, 'st-9')).toBeNull()
		expect(nextStepOf(null, 'st-1')).toBeNull()
		expect(nextStepOf(STATUSES, '')).toBeNull()
	})

	it('reads a language-tagged status name in the reader\'s language, never as [object Object]', () => {
		const tagged = [
			{ id: 'st-1', name: { nl: 'Ontvangen', en: 'Received' }, order: 1 },
			{ id: 'st-2', name: { nl: 'Besluit', en: 'Decision' }, order: 2 },
		]
		globalThis.OC = { getLanguage: () => 'nl' }
		expect(nextStepOf(tagged, 'st-1')).toEqual({ number: 2, name: 'Besluit' })
		globalThis.OC = { getLanguage: () => 'en' }
		expect(nextStepOf(tagged, 'st-1')).toEqual({ number: 2, name: 'Decision' })
		delete globalThis.OC
	})
})
