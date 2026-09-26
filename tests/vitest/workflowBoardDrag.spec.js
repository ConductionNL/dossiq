// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The drag is Sortable's (vue-draggable-plus), and the board answers it in
 * two places: while the card is in the air, which columns refuse it and why;
 * and when it lands, the same transition post the dialog and the case page
 * make. Both halves are driven here through the methods the column calls,
 * because a pointer drag cannot run under jsdom. The column's own tests at
 * the bottom check what it hands Sortable and what it reads back off the
 * dragged element, which is the contract the board's half depends on.
 *
 * `saveObject` is asserted never-called on the landing path, as in
 * `workflowBoardMove.spec.js`: writing the status directly is the defect
 * that spec exists to keep out, and the drag must not bring it back.
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
 * @spec openspec/specs/status-transition-engine/spec.md#requirement-transition-execution
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const { showError, showWarning, saveObject, fetchCollection } = vi.hoisted(() => ({
	showError: vi.fn(),
	showWarning: vi.fn(),
	saveObject: vi.fn(),
	fetchCollection: vi.fn(),
}))

vi.mock('@nextcloud/dialogs', () => ({ showError, showWarning }))

vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', render: () => h('button') },
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span') },
	NcPopover: {
		name: 'NcPopover',
		render() {
			return h('div', [
				this.$slots.trigger ? this.$slots.trigger() : null,
				this.$slots.default ? this.$slots.default() : null,
			])
		},
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		render: () => h('input'),
	},
	NcDialog: { name: 'NcDialog', render: () => h('div') },
	NcNoteCard: { name: 'NcNoteCard', render: () => h('div') },
	NcSelect: { name: 'NcSelect', render: () => h('div') },
}))

// Sortable itself stays out: the stub renders the list and declares the
// options the column is expected to set, so they can be read back as props.
vi.mock('vue-draggable-plus', () => ({
	VueDraggable: {
		name: 'VueDraggable',
		props: [
			'modelValue',
			'group',
			'sort',
			'animation',
			'draggable',
			'filter',
			'preventOnFilter',
			'forceFallback',
			'fallbackOnBody',
			'ghostClass',
			'chosenClass',
			'dragClass',
		],
		render() {
			return h(
				'div',
				{ 'data-testid': 'draggable' },
				this.$slots.default ? this.$slots.default() : [],
			)
		},
	},
}))

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
const BoardColumn = (await import('../../src/views/workflow-board/BoardColumn.vue'))
	.default

const CASE = { id: 'case-1', title: 'Kapotte lantaarnpaal', caseType: 'ct-1' }

const OFFERED = {
	id: 't1',
	label: 'Start behandeling',
	toStatus: 'st-progress',
	guardsPassed: true,
	failedGuards: [],
}

const BLOCKED = {
	...OFFERED,
	guardsPassed: false,
	failedGuards: [
		{ type: 'requiredDocument', failureMessage: 'Upload the decision first.' },
	],
}

/**
 * Mount the board with the case in "Ontvangen" and three columns, one of
 * which ("Besluitvorming") the engine never offers.
 *
 * @param {Array<object>} transitions What `/available-transitions` answers.
 * @return {Promise<object>} The mounted wrapper.
 */
async function board(transitions) {
	fetchCollection.mockResolvedValue([])
	axios.get.mockResolvedValue({ data: { transitions } })

	const wrapper = shallowMount(WorkflowBoard, {
		global: { stubs: { BoardColumn: true } },
	})
	await flushPromises()

	wrapper.vm.loading = false
	wrapper.vm.columns = [
		{ id: 'Ontvangen', name: 'Ontvangen', order: 1 },
		{ id: 'In behandeling', name: 'In behandeling', order: 2 },
		{ id: 'Besluitvorming', name: 'Besluitvorming', order: 3 },
	]
	wrapper.vm.casesByStatus = {
		Ontvangen: [{ ...CASE, status: 'st-received' }],
		'In behandeling': [],
		Besluitvorming: [],
	}
	wrapper.vm.statusIdByTypeAndName = {
		'ct-1::Ontvangen': 'st-received',
		'ct-1::In behandeling': 'st-progress',
		'ct-1::Besluitvorming': 'st-decision',
	}
	return wrapper
}

/**
 * What Sortable's wrapper has done by the time `add` fires: the card is in
 * the target list and gone from the source.
 *
 * @param {object} wrapper The mounted board.
 * @return {void}
 */
function wrapperMovedTheCard(wrapper) {
	wrapper.vm.casesByStatus = {
		Ontvangen: [],
		'In behandeling': [{ ...CASE, status: 'st-received' }],
		Besluitvorming: [],
	}
}

/**
 * @param {object} wrapper The mounted board.
 * @param {string} column The column name.
 * @return {Array<string>} The ids of the cases in that column.
 */
function idsIn(wrapper, column) {
	return (wrapper.vm.casesByStatus[column] || []).map((c) => String(c.id))
}

describe('while a card is in the air', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('asks the engine once, at drag start, and refuses the columns it does not offer', async () => {
		const wrapper = await board([OFFERED])

		await wrapper.vm.onDragStart('case-1')

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get.mock.calls[0][0]).toBe(
			'/index.php/apps/dossiq/api/case/case-1/available-transitions',
		)
		expect(wrapper.vm.canDropInto('In behandeling')).toBe(true)
		expect(wrapper.vm.canDropInto('Besluitvorming')).toBe(false)
		expect(wrapper.vm.dropStateFor('In behandeling')).toMatchObject({
			allowed: true,
		})
		expect(wrapper.vm.dropStateFor('Besluitvorming')).toMatchObject({
			allowed: false,
			blocked: false,
		})
		// The column it came from is neither reachable nor refused.
		expect(wrapper.vm.dropStateFor('Ontvangen')).toBeNull()
	})

	it('lets every column accept until the answer lands', async () => {
		const wrapper = await board([])
		let answer
		axios.get.mockReturnValue(
			new Promise((resolve) => {
				answer = resolve
			}),
		)

		const started = wrapper.vm.onDragStart('case-1')

		expect(wrapper.vm.canDropInto('In behandeling')).toBe(true)
		expect(wrapper.vm.dropStateFor('In behandeling')).toMatchObject({
			allowed: null,
		})

		answer({ data: { transitions: [] } })
		await started

		expect(wrapper.vm.canDropInto('In behandeling')).toBe(false)
	})

	it('says which guard holds the case, under the column it cannot enter', async () => {
		const wrapper = await board([BLOCKED])

		await wrapper.vm.onDragStart('case-1')

		expect(wrapper.vm.canDropInto('In behandeling')).toBe(false)
		expect(wrapper.vm.dropStateFor('In behandeling')).toEqual({
			allowed: false,
			blocked: true,
			reason: 'Upload the decision first.',
		})
	})

	it("refuses a column outside the case's own workflow before the engine answers", async () => {
		const wrapper = await board([OFFERED])
		axios.get.mockReturnValue(new Promise(() => {}))

		wrapper.vm.onDragStart('case-1')

		expect(wrapper.vm.canDropInto('Iets heel anders')).toBe(false)
	})

	it('keeps every column open when the offer could not be read', async () => {
		const wrapper = await board([OFFERED])
		axios.get.mockRejectedValue(new Error('offline'))

		await wrapper.vm.onDragStart('case-1')

		expect(wrapper.vm.canDropInto('In behandeling')).toBe(true)
		expect(wrapper.vm.dropStateFor('In behandeling')).toMatchObject({
			allowed: null,
		})
	})

	it('forgets the drag when the card is let go', async () => {
		const wrapper = await board([])
		await wrapper.vm.onDragStart('case-1')
		expect(wrapper.vm.draggedCaseId).toBe('case-1')

		wrapper.vm.onDragEnd()

		expect(wrapper.vm.drag).toBeNull()
		expect(wrapper.vm.draggedCaseId).toBeNull()
		expect(wrapper.vm.canDropInto('In behandeling')).toBe(true)
		expect(wrapper.vm.dropStateFor('In behandeling')).toBeNull()
	})
})

describe('when the card lands', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('posts the transition from the offer read at drag start, without asking again', async () => {
		const wrapper = await board([OFFERED])
		axios.post.mockResolvedValue({ data: { status: 'ok' } })
		wrapper.vm.fetchData = vi.fn(async () => {})
		await wrapper.vm.onDragStart('case-1')

		wrapperMovedTheCard(wrapper)
		const landed = wrapper.vm.onCardDropped({
			caseId: 'case-1',
			fromColumn: 'Ontvangen',
			toColumn: 'In behandeling',
		})
		// Sortable fires `end` right after `add`, before anything awaited.
		wrapper.vm.onDragEnd()
		await landed

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/dossiq/api/case/case-1/transition',
			{ transitionId: 't1' },
		)
		expect(saveObject).not.toHaveBeenCalled()
		expect(idsIn(wrapper, 'In behandeling')).toEqual(['case-1'])
		expect(wrapper.vm.casesByStatus['In behandeling'][0].status).toBe(
			'st-progress',
		)
	})

	it('asks the engine on landing when the drag never got an answer', async () => {
		const wrapper = await board([OFFERED])
		axios.post.mockResolvedValue({ data: { status: 'ok' } })
		wrapper.vm.fetchData = vi.fn(async () => {})

		wrapperMovedTheCard(wrapper)
		await wrapper.vm.onCardDropped({
			caseId: 'case-1',
			fromColumn: 'Ontvangen',
			toColumn: 'In behandeling',
		})

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(idsIn(wrapper, 'In behandeling')).toEqual(['case-1'])
	})

	it('puts the card back and names the reason when the engine refuses', async () => {
		const wrapper = await board([OFFERED])
		axios.post.mockRejectedValue({
			response: {
				status: 400,
				data: { error: 'Could not execute transition' },
			},
		})

		wrapperMovedTheCard(wrapper)
		await wrapper.vm.onCardDropped({
			caseId: 'case-1',
			fromColumn: 'Ontvangen',
			toColumn: 'In behandeling',
		})

		expect(saveObject).not.toHaveBeenCalled()
		expect(idsIn(wrapper, 'In behandeling')).toEqual([])
		expect(idsIn(wrapper, 'Ontvangen')).toEqual(['case-1'])
		expect(showError).toHaveBeenCalledWith('Could not execute transition')
	})

	it("puts the card back when the column is not in the case's workflow", async () => {
		const wrapper = await board([OFFERED])
		wrapper.vm.casesByStatus = {
			Ontvangen: [],
			'Iets heel anders': [{ ...CASE, status: 'st-received' }],
		}

		await wrapper.vm.onCardDropped({
			caseId: 'case-1',
			fromColumn: 'Ontvangen',
			toColumn: 'Iets heel anders',
		})

		expect(axios.post).not.toHaveBeenCalled()
		expect(idsIn(wrapper, 'Iets heel anders')).toEqual([])
		expect(idsIn(wrapper, 'Ontvangen')).toEqual(['case-1'])
		expect(showError).toHaveBeenCalledWith(
			"That status is not part of this case's workflow.",
		)
	})

	it('does nothing for a card let go in its own column', async () => {
		const wrapper = await board([OFFERED])

		await wrapper.vm.onCardDropped({
			caseId: 'case-1',
			fromColumn: 'Ontvangen',
			toColumn: 'Ontvangen',
		})

		expect(axios.get).not.toHaveBeenCalled()
		expect(axios.post).not.toHaveBeenCalled()
		expect(idsIn(wrapper, 'Ontvangen')).toEqual(['case-1'])
	})
})

describe('the column hands the drag to Sortable', () => {
	const STATUS = { id: 'In behandeling', name: 'In behandeling', order: 2 }

	/**
	 * @param {object} props Extra props for the column.
	 * @return {object} The mounted column, with the card stubbed.
	 */
	function column(props = {}) {
		return mount(BoardColumn, {
			props: { statusType: STATUS, cases: [], ...props },
			global: { stubs: { CaseCard: true } },
		})
	}

	/**
	 * @param {Object<string, string>} dataset What the element carries in `data-*`.
	 * @return {HTMLElement} The element.
	 */
	function element(dataset) {
		const el = document.createElement('div')
		Object.assign(el.dataset, dataset)
		return el
	}

	it('renders a drop target even when it holds no cards', () => {
		const wrapper = column()

		expect(wrapper.find('[data-testid="draggable"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('No cases')
	})

	it('configures one shared group, no in-column sorting and the fallback ghost', () => {
		const list = column().findComponent({ name: 'VueDraggable' })

		expect(list.props()).toMatchObject({
			group: 'dossiq-board',
			sort: false,
			draggable: '.case-card',
			// The selection checkbox is clicked, not dragged.
			filter: '.case-card__select',
			preventOnFilter: false,
			// Sortable's own clone under the pointer, not the browser's.
			forceFallback: true,
			fallbackOnBody: true,
			ghostClass: 'case-card--ghost',
			chosenClass: 'case-card--chosen',
			dragClass: 'case-card--dragging',
		})
	})

	it('names the case and the source column from the dragged element', () => {
		const wrapper = column()

		wrapper.vm.onStart({ item: element({ caseId: 'case-1' }) })
		wrapper.vm.onAdd({
			item: element({ caseId: 'case-1' }),
			from: element({ columnId: 'Ontvangen' }),
		})

		expect(wrapper.emitted('dragstart')).toEqual([['case-1']])
		expect(wrapper.emitted('cardDropped')).toEqual([
			[
				{
					caseId: 'case-1',
					fromColumn: 'Ontvangen',
					toColumn: 'In behandeling',
				},
			],
		])
	})

	it('asks the board before letting the card into another column, and always lets it come home', () => {
		const canDrop = vi.fn(() => false)
		const wrapper = column({ canDrop })

		expect(
			wrapper.vm.onMove({ to: element({ columnId: 'Besluitvorming' }) }),
		).toBe(false)
		expect(canDrop).toHaveBeenCalledWith('Besluitvorming')
		expect(
			wrapper.vm.onMove({ to: element({ columnId: 'In behandeling' }) }),
		).toBe(true)
	})

	it('fades a refused column and says what holds the case', async () => {
		const wrapper = column({
			dropState: {
				allowed: false,
				blocked: true,
				reason: 'Upload the decision first.',
			},
		})

		expect(wrapper.classes()).toContain('board-column--refused')
		expect(wrapper.find('[data-testid="board-column-hint"]').text()).toBe(
			'Upload the decision first.',
		)

		await wrapper.setProps({
			dropState: { allowed: false, blocked: true, reason: '' },
		})
		expect(wrapper.find('[data-testid="board-column-hint"]').text()).toBe(
			'Something is holding this case here.',
		)

		await wrapper.setProps({
			dropState: { allowed: false, blocked: false, reason: '' },
		})
		expect(wrapper.find('[data-testid="board-column-hint"]').exists()).toBe(
			false,
		)
	})

	it('marks a column the card can reach, and nothing while nothing is dragged', async () => {
		const wrapper = column({
			dropState: { allowed: true, blocked: false, reason: '' },
		})
		expect(wrapper.classes()).toContain('board-column--reachable')

		await wrapper.setProps({ dropState: null })
		expect(wrapper.classes()).not.toContain('board-column--reachable')
		expect(wrapper.classes()).not.toContain('board-column--refused')
	})

	it("forwards the wrapper's list update to the board", () => {
		const wrapper = column()
		const moved = [{ ...CASE, status: 'st-received' }]

		wrapper
			.findComponent({ name: 'VueDraggable' })
			.vm.$emit('update:modelValue', moved)

		expect(wrapper.emitted('update:cases')).toEqual([[moved]])
	})
})
