// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for DeelzaakCreateModal's `submit`
 * (src/modals/DeelzaakCreateModal.vue): the server-side check, the record it
 * derives (with a frozen clock), what it emits, and every refusal path.
 *
 * @spec openspec/specs/deelzaak-support/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

let currentUser = { uid: 'behandelaar' }
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => currentUser }))
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
const refreshes = []
vi.mock('@nextcloud/event-bus', () => ({ emit: (name) => refreshes.push(name) }))
const createSubCase = vi.fn()
vi.mock('../../src/services/deelzaakApi.js', () => ({ createSubCase: (args) => createSubCase(args) }))

const { default: DeelzaakCreateModal } = await import('../../src/modals/DeelzaakCreateModal.vue')

const NOW = new Date('2026-03-02T10:30:00Z')

/**
 * A stand-in for the modal instance `submit` runs on.
 *
 * @param {object} caseType The selected sub-case type.
 * @param {object} check What the server-side validation answers.
 * @return {object} The instance, with what it emitted.
 */
function instance(caseType, check = { ok: true }) {
	const emitted = []
	return {
		emitted,
		serverError: 'old',
		saving: false,
		parentCase: 'parent-1',
		selectedCaseType: caseType,
		form: { title: '  Advies brandweer ', description: ' Graag binnen tien dagen ', caseType: caseType.id },
		validate: () => true,
		deelzaakStore: { validateSubCase: vi.fn().mockResolvedValue(check) },
		$emit: (name, value) => emitted.push([name, value]),
	}
}

const FULL_TYPE = {
	id: 'ct-child',
	title: 'Advies',
	processingDeadline: 'P10D',
	confidentiality: 'confidential',
	defaultAssignee: 'jan',
	initialStatus: { id: 'st-1' },
}

describe('DeelzaakCreateModal submit', () => {
	beforeEach(() => {
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(NOW)
		vi.spyOn(console, 'error').mockImplementation(() => {})
		createSubCase.mockReset()
		createSubCase.mockResolvedValue({ ok: true, object: { id: 'sub-1' } })
		refreshes.length = 0
		currentUser = { uid: 'behandelaar' }
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	it('does nothing but clear the error when the form is invalid', async () => {
		const vm = { ...instance(FULL_TYPE), validate: () => false }
		await DeelzaakCreateModal.methods.submit.call(vm)
		expect(vm.serverError).toBe('')
		expect(vm.saving).toBe(false)
		expect(vm.deelzaakStore.validateSubCase).not.toHaveBeenCalled()
	})

	it('derives the full sub-case record and emits the created id', async () => {
		const vm = instance(FULL_TYPE)
		await DeelzaakCreateModal.methods.submit.call(vm)
		expect(vm.deelzaakStore.validateSubCase).toHaveBeenCalledWith({ parentCaseUuid: 'parent-1', childCaseTypeId: 'ct-child' })
		expect(createSubCase).toHaveBeenCalledTimes(1)
		expect(createSubCase.mock.calls[0][0]).toEqual({
			parentCaseUuid: 'parent-1',
			childCaseTypeId: 'ct-child',
			object: {
				title: 'Advies brandweer',
				description: 'Graag binnen tien dagen',
				identifier: '2026-' + String(NOW.getTime() % 10000).padStart(4, '0'),
				caseType: 'ct-child',
				status: 'st-1',
				startDate: '2026-03-02T00:00:00Z',
				deadline: '2026-03-12T17:00:00Z',
				confidentiality: 'confidential',
				assignee: 'jan',
				intakeChannel: 'manual',
				priority: 'normal',
				endDate: null,
				result: null,
				extensionCount: 0,
				parentCase: 'parent-1',
				statusHistory: JSON.stringify([{ status: 'st-1', date: NOW.toISOString(), changedBy: 'behandelaar' }]),
				activity: JSON.stringify([{
					date: NOW.toISOString(),
					type: 'created',
					description: 'Sub-case created with type \'Advies\'',
					user: 'behandelaar',
				}]),
			},
		})
		expect(Object.keys(createSubCase.mock.calls[0][0].object)).toEqual([
			'title', 'description', 'identifier', 'caseType', 'status', 'startDate', 'deadline',
			'confidentiality', 'assignee', 'intakeChannel', 'priority', 'endDate', 'result',
			'extensionCount', 'parentCase', 'statusHistory', 'activity',
		])
		expect(vm.emitted).toEqual([['created', 'sub-1'], ['close', undefined]])
		expect(refreshes).toEqual(['cn:page:refresh'])
		expect(vm.serverError).toBe('')
		expect(vm.saving).toBe(false)
	})

	it('leaves out the status and falls back on a bare case type and no user', async () => {
		currentUser = null
		const vm = instance({ id: 'ct-bare', title: 'Kaal' })
		await DeelzaakCreateModal.methods.submit.call(vm)
		const record = createSubCase.mock.calls[0][0].object
		expect('status' in record).toBe(false)
		expect(record.deadline).toBeNull()
		expect(record.confidentiality).toBe('zaakvertrouwelijk')
		expect(record.assignee).toBeNull()
		expect(JSON.parse(record.statusHistory)).toEqual([{ status: null, date: NOW.toISOString(), changedBy: 'unknown' }])
		expect(JSON.parse(record.activity)[0].user).toBe('unknown')
	})

	it('falls back to unknown for a user without a uid', async () => {
		currentUser = { uid: '' }
		await DeelzaakCreateModal.methods.submit.call(instance(FULL_TYPE))
		expect(JSON.parse(createSubCase.mock.calls[0][0].object.statusHistory)[0].changedBy).toBe('unknown')
	})

	it.each([
		[{ ok: false, reason: 'Not allowed here.' }, 'Not allowed here.'],
		[{ ok: false }, 'Sub-case validation failed.'],
	])('stops on the server check %j', async (check, message) => {
		const vm = instance(FULL_TYPE, check)
		await DeelzaakCreateModal.methods.submit.call(vm)
		expect(vm.serverError).toBe(message)
		expect(vm.saving).toBe(false)
		expect(createSubCase).not.toHaveBeenCalled()
		expect(vm.emitted).toEqual([])
	})

	it.each([null, undefined, { ok: true }])('goes on when the server check answers %j', async (check) => {
		await DeelzaakCreateModal.methods.submit.call(instance(FULL_TYPE, check))
		expect(createSubCase).toHaveBeenCalledTimes(1)
	})

	it.each([
		[{ ok: false, reason: 'Derive refused.' }, 'Derive refused.'],
		[{ ok: false }, 'Failed to create sub-case.'],
	])('shows a refused derive %j', async (result, message) => {
		createSubCase.mockResolvedValue(result)
		const vm = instance(FULL_TYPE)
		await DeelzaakCreateModal.methods.submit.call(vm)
		expect(vm.serverError).toBe(message)
		expect(vm.emitted).toEqual([])
		expect(refreshes).toEqual([])
		expect(vm.saving).toBe(false)
	})

	it.each([
		[{ ok: true, object: { '@self': { id: 'self-1' } } }, 'self-1'],
		[{ ok: true, object: { title: 'x' } }, { title: 'x' }],
		[{ ok: true }, {}],
		[null, {}],
	])('emits what it can name of the created case for %j', async (result, created) => {
		createSubCase.mockResolvedValue(result)
		const vm = instance(FULL_TYPE)
		await DeelzaakCreateModal.methods.submit.call(vm)
		expect(vm.emitted).toEqual([['created', created], ['close', undefined]])
	})

	it.each([
		[{ response: { data: { message: 'Server says no.' } }, message: 'ignored' }, 'Server says no.'],
		[new Error('network down'), 'network down'],
		[{}, 'Failed to create sub-case.'],
	])('shows a thrown failure', async (thrown, message) => {
		createSubCase.mockRejectedValue(thrown)
		const vm = instance(FULL_TYPE)
		await DeelzaakCreateModal.methods.submit.call(vm)
		expect(vm.serverError).toBe(message)
		expect(console.error).toHaveBeenCalledWith('[DeelzaakCreateModal] Failed to create sub-case', thrown)
		expect(vm.saving).toBe(false)
	})
})
