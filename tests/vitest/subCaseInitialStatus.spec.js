// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A sub-case starts in the initial status of its own case type.
 *
 * OpenRegister starts a created case in the case type's `initialStatus`
 * and refuses another start state, so the modal sends that status, or no
 * status at all when the case type declares none.
 *
 * @spec openspec/specs/deelzaak-support/spec.md#requirement-sub-case-creation-from-parent-case
 */

import { describe, expect, it, vi } from 'vitest'
import { subCaseInitialStatus } from '../../src/utils/deelzaakHelpers.js'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'behandelaar' }) }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcDialog: {},
	NcEmptyContent: {},
	NcLoadingIcon: {},
	NcSelect: {},
	NcTextField: {},
}))
vi.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({ default: {} }))
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => ({}) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('@nextcloud/event-bus', () => ({ emit: () => {} }))
const sent = []
vi.mock('../../src/services/deelzaakApi.js', () => ({
	createSubCase: async (request) => {
		sent.push(request.object)
		return { ok: true, object: { id: 'sub-1' } }
	},
}))

const { default: DeelzaakCreateModal } = await import('../../src/modals/DeelzaakCreateModal.vue')

/**
 * Submit the modal for one selected sub-case type and return the case it sent.
 *
 * @param {object} caseType The selected sub-case type.
 * @return {Promise<object>} The case object passed to createSubCase.
 */
async function submitFor(caseType) {
	sent.length = 0
	const context = {
		serverError: '',
		saving: false,
		parentCase: 'parent-1',
		selectedCaseType: { title: 'Advies', processingDeadline: 'P10D', ...caseType },
		statusTypes: [{ id: 'st-lowest-order', order: 0 }],
		form: { title: 'Advies brandweer', description: '', caseType: caseType.id },
		validate: () => true,
		deelzaakStore: { validateSubCase: async () => ({ ok: true }) },
		$emit: () => {},
	}
	await DeelzaakCreateModal.methods.submit.call(context)
	return sent[0]
}

describe('the status a new sub-case is sent with', () => {
	it('is the initial status of the sub-case type', async () => {
		const object = await submitFor({ id: 'ct-child', initialStatus: 'st-initial' })
		expect(object.status).toBe('st-initial')
		expect(JSON.parse(object.statusHistory)[0].status).toBe('st-initial')
	})

	it('is left out when the sub-case type declares no initial status', async () => {
		const object = await submitFor({ id: 'ct-child' })
		expect(object).not.toHaveProperty('status')
	})
})

describe('subCaseInitialStatus', () => {
	it('reads a uuid or an expanded status object', () => {
		expect(subCaseInitialStatus({ initialStatus: 'st-1' })).toBe('st-1')
		expect(subCaseInitialStatus({ initialStatus: { id: 'st-2' } })).toBe('st-2')
	})

	it('answers null when there is none', () => {
		expect(subCaseInitialStatus({})).toBeNull()
		expect(subCaseInitialStatus(null)).toBeNull()
		expect(subCaseInitialStatus({ initialStatus: '' })).toBeNull()
	})
})
