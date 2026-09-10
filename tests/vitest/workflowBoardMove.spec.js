// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A board move is the same gesture as a case-page move.
 *
 * The board used to move a card by writing `case.status` through
 * `saveObject('case', ...)`. That reaches OpenRegister and stops: dossiq's
 * `case` schema carries no `x-openregister-lifecycle`, so OR's
 * LifecycleValidationListener returns before it looks, and the transition's
 * role check, its guards, its configured actions and its statusRecord are all
 * skipped. The same move made from the case page passes through every one of
 * them. One surface, two meanings.
 *
 * So the assertions here are about the REQUEST, not the rendering. The first
 * test drives both surfaces and requires them to produce the same POST: that
 * is the whole claim, and it is the one a screenshot cannot make. The rest
 * cover what a refusal has to do, because a card that slides silently home
 * reads as a broken board rather than a refused move.
 *
 * `saveObject` is asserted NEVER-CALLED in every test rather than only in the
 * first. It is the defect's fingerprint, and a later edit that reintroduces
 * it on one branch of the handler would otherwise still pass here.
 *
 * @spec openspec/specs/status-transition-engine/spec.md#requirement-transition-execution
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const { showError, saveObject, fetchCollection } = vi.hoisted(() => ({
	showError: vi.fn(),
	saveObject: vi.fn(),
	fetchCollection: vi.fn(),
}))

vi.mock('@nextcloud/dialogs', () => ({ showError }))

// The board's own children (BoardColumn -> CaseCard) are stubbed at mount,
// but their MODULES are still evaluated, so every root `@nextcloud/vue`
// export the tree names has to exist here or the whole file fails to
// collect. Listing them beats a Proxy: a new import shows up as a named
// failure rather than a silently working stub.
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', render: () => h('button') },
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span') },
	NcActions: { name: 'NcActions', render: () => h('div') },
	NcActionButton: { name: 'NcActionButton', render: () => h('button') },
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		render: () => h('input'),
	},
}))

// One store object for every call: the board reads `objectStore` from a
// computed AND watches `objectStore.liveLastEventAt`, and a fresh object per
// access would retrigger the watcher on every render.
const store = {
	saveObject,
	fetchCollection,
	liveLastEventAt: 0,
	objectTypeRegistry: {},
}
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => store,
}))
vi.mock('../../src/store/store.js', () => ({
	initializeStores: vi.fn(async () => {}),
}))
vi.mock('../../src/dialogs/BulkTransitionDialog.vue', () => ({
	default: { name: 'BulkTransitionDialog', render: () => null },
}))

const WorkflowBoard = (
	await import('../../src/views/workflow-board/WorkflowBoard.vue')
).default
const CaseTransitionConfirmDialog = (
	await import('../../src/dialogs/CaseTransitionConfirmDialog.vue')
).default

/** The case the board moves. */
const CASE = { id: 'case-1', title: 'Kapotte lantaarnpaal', caseType: 'ct-1' }

/** The transition that ends on the dropped column. */
const OFFERED = {
	id: 't1',
	label: 'Start behandeling',
	toStatus: 'st-progress',
	guardsPassed: true,
	failedGuards: [],
}

/**
 * Mount the board and put it in the state a drag starts from: the case sits
 * in "Ontvangen", and "In behandeling" resolves to a status id in the case's
 * own case type.
 *
 * The model is seeded directly rather than through `fetchData`. The subject
 * is the move, and driving three mocked collections into the grouping code
 * first would make a failure in either half read as a failure in this one.
 *
 * @param {Array<object>} transitions What `/available-transitions` answers.
 * @return {Promise<object>} The mounted wrapper.
 */
async function boardWithOffer(transitions) {
	fetchCollection.mockResolvedValue([])
	axios.get.mockResolvedValue({ data: { transitions } })

	const wrapper = shallowMount(WorkflowBoard, {
		global: { stubs: { BoardColumn: true } },
	})
	// `mounted()` awaits initializeStores() and then fetchData(); seeding the
	// model before that settles would have fetchData's own (empty) grouping
	// overwrite it, and every assertion below would fail on a board that never
	// held the card.
	await flushPromises()

	wrapper.vm.loading = false
	wrapper.vm.columns = [
		{ id: 'Ontvangen', name: 'Ontvangen', order: 1 },
		{ id: 'In behandeling', name: 'In behandeling', order: 2 },
	]
	wrapper.vm.casesByStatus = {
		Ontvangen: [{ ...CASE, status: 'st-received' }],
		'In behandeling': [],
	}
	wrapper.vm.statusIdByTypeAndName = {
		'ct-1::Ontvangen': 'st-received',
		'ct-1::In behandeling': 'st-progress',
	}
	return wrapper
}

/**
 * The case-page confirm dialog, mounted on the same transition.
 *
 * @return {object} The mounted wrapper.
 */
function casePageDialog() {
	return shallowMount(CaseTransitionConfirmDialog, {
		props: { caseId: CASE.id, transition: OFFERED, closing: false },
	})
}

/**
 * The ids of the cases currently rendered in one column.
 *
 * @param {object} wrapper The mounted board.
 * @param {string} column The column name.
 * @return {Array<string>} The case ids.
 */
function idsIn(wrapper, column) {
	return (wrapper.vm.casesByStatus[column] || []).map((c) => String(c.id))
}

describe('moving a case on the workflow board', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('posts the same transition the case page posts, and never writes the status directly', async () => {
		const wrapper = await boardWithOffer([OFFERED])
		axios.post.mockResolvedValue({ data: { status: 'ok' } })

		await wrapper.vm.onDrop('case-1', 'In behandeling')

		expect(saveObject).not.toHaveBeenCalled()
		expect(axios.post).toHaveBeenCalledTimes(1)
		const fromBoard = axios.post.mock.calls[0]

		// The identical move, made the other way.
		axios.post.mockClear()
		await casePageDialog().vm.confirm()
		const fromCasePage = axios.post.mock.calls[0]

		expect(fromBoard[0]).toBe(
			'/index.php/apps/dossiq/api/case/case-1/transition',
		)
		expect(fromBoard[0]).toBe(fromCasePage[0])
		expect(fromBoard[1]).toEqual({ transitionId: 't1' })
		expect(fromBoard[1]).toEqual(fromCasePage[1])
	})

	it('puts the card back and names the reason when the engine refuses the move', async () => {
		const wrapper = await boardWithOffer([OFFERED])
		// The shape StatusTransitionController answers a `transition_unauthorized`
		// with: a coded refusal behind its static message.
		axios.post.mockRejectedValue({
			response: { status: 400, data: { error: 'Could not execute transition' } },
		})

		await wrapper.vm.onDrop('case-1', 'In behandeling')

		expect(saveObject).not.toHaveBeenCalled()
		expect(idsIn(wrapper, 'In behandeling')).toEqual([])
		expect(idsIn(wrapper, 'Ontvangen')).toEqual(['case-1'])
		expect(showError).toHaveBeenCalledWith('Could not execute transition')
	})

	it('shows the guard that is holding the case, without attempting the move', async () => {
		const wrapper = await boardWithOffer([
			{
				...OFFERED,
				guardsPassed: false,
				failedGuards: [
					{ type: 'requiredDocument', failureMessage: 'Upload the decision first.' },
				],
			},
		])

		await wrapper.vm.onDrop('case-1', 'In behandeling')

		expect(saveObject).not.toHaveBeenCalled()
		expect(axios.post).not.toHaveBeenCalled()
		expect(idsIn(wrapper, 'Ontvangen')).toEqual(['case-1'])
		expect(showError).toHaveBeenCalledWith('Upload the decision first.')
	})

	it('refuses a move the engine does not offer at all', async () => {
		// What a user whose role hides the transition gets: the engine drops it
		// from the answer, exactly as the case page renders no button.
		const wrapper = await boardWithOffer([])

		await wrapper.vm.onDrop('case-1', 'In behandeling')

		expect(saveObject).not.toHaveBeenCalled()
		expect(axios.post).not.toHaveBeenCalled()
		expect(idsIn(wrapper, 'In behandeling')).toEqual([])
		expect(idsIn(wrapper, 'Ontvangen')).toEqual(['case-1'])
		expect(showError).toHaveBeenCalledWith(
			'You cannot move this case to In behandeling from here.',
		)
	})
})
