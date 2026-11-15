/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Contact as a kind of requester on the new case form: the form's whole choice
 * becomes the four requester fields, and never reaches the case itself.
 *
 * @spec openspec/specs/initiator-selection/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const saveObject = vi.hoisted(() => vi.fn())
const initiatorChoice = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, one) => one }))
vi.mock('@conduction/nextcloud-vue', () => ({
	candidateWithoutBlanks: (candidate) => candidate,
	duplicateAffordances: () => ({ show: false }),
	openDuplicateWarning: vi.fn(),
	useObjectStore: () => ({ saveObject }),
}))
vi.mock('../../src/services/duplicateCheckApi.js', () => ({
	checkForDuplicates: async () => ({ matches: [], checked: true }),
	fetchMatchedCases: async () => [],
}))
vi.mock('../../src/services/intakeTriageApi.js', () => ({ fetchIntakeRequirements: async () => ({}) }))
vi.mock('../../src/services/initiatorSearch.js', () => ({ initiatorChoice }))

const { createCaseWithDuplicateCheck } = await import('../../src/services/createCaseWithDuplicateCheck.js')

describe('the requester choice on a new case', () => {
	beforeEach(() => {
		saveObject.mockReset().mockResolvedValue({ id: 'case-1' })
		initiatorChoice.mockReset()
	})

	it('saves a contact without a party as its projection, with no requester', async () => {
		initiatorChoice.mockResolvedValue({ requester: '', initiatorType: 'contact', initiatorSourceId: 'c1', initiatorDisplayName: 'H. Visser' })
		await createCaseWithDuplicateCheck({ title: 'T', requester: 'c1', requesterChoice: { source: 'contact', row: { id: 'c1' } } }, { type: 'case' })
		expect(initiatorChoice).toHaveBeenCalledWith('contact', { id: 'c1' })
		expect(saveObject).toHaveBeenCalledWith('case', { title: 'T', initiatorType: 'contact', initiatorSourceId: 'c1', initiatorDisplayName: 'H. Visser' })
	})

	it('points a contact at the party that holds its address', async () => {
		initiatorChoice.mockResolvedValue({ requester: 'party-1', initiatorType: 'contact', initiatorSourceId: 'c1', initiatorDisplayName: 'H. Visser' })
		await createCaseWithDuplicateCheck({ requester: 'c1', requesterChoice: { source: 'contact', row: { id: 'c1' } } }, { type: 'case' })
		expect(saveObject.mock.calls[0][1].requester).toBe('party-1')
	})

	it('leaves a form without a choice as it is, and drops a cleared one', async () => {
		await createCaseWithDuplicateCheck({ title: 'T', requesterChoice: null }, { type: 'case' })
		expect(saveObject).toHaveBeenCalledWith('case', { title: 'T' })
		expect(initiatorChoice).not.toHaveBeenCalled()
	})
})
