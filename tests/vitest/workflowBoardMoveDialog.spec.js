// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Asking to move a card, now that the card has no move menu.
 *
 * The card used to carry an NcActions listing every board column — one per
 * status NAME across every case type on the instance, so two hundred items on
 * a real register, almost none of them statuses the case could reach. It is
 * replaced by two gestures that both end in one dialog: right-click, and M on
 * the focused card.
 *
 * WHAT THESE TESTS ARE FOR, and it is not the dialog's markup. Three things
 * have to hold or the redesign is a regression:
 *
 *  1. The card offers no move control and no long menu any more. Asserted by
 *     the ABSENCE of the old control, because a leftover would mean two ways
 *     to move a card that disagree about what is offered.
 *  2. Both gestures reach the engine's offer for THAT case, not the board's
 *     columns. This is the entire point: the list has to be short because it
 *     is correct, not merely trimmed.
 *  3. Confirming goes through `onDrop`, the drag path — role check, guard
 *     evaluation, optimistic move and revert included. A second write path
 *     would be the `saveObject` bug the board's own comment was written
 *     about.
 *
 * `workflowBoardMove.spec.js` covers what happens after that, on the drop
 * path both gestures share.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const { showError, showWarning, fetchCollection } = vi.hoisted(() => ({
	showError: vi.fn(),
	showWarning: vi.fn(),
	fetchCollection: vi.fn(),
}))

vi.mock('@nextcloud/dialogs', () => ({ showError, showWarning }))

vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: { disabled: { type: Boolean, default: false } },
		render() {
			return h(
				'button',
				{ disabled: this.disabled, onClick: () => this.$emit('click') },
				this.$slots.default ? this.$slots.default() : [],
			)
		},
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span') },
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		render: () => h('input'),
	},
	NcDialog: {
		name: 'NcDialog',
		render() {
			return h('div', { 'data-testid': 'dialog' }, [
				this.$slots.default ? this.$slots.default() : null,
				this.$slots.actions ? this.$slots.actions() : null,
			])
		},
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		render() {
			return h(
				'div',
				{ 'data-testid': 'note' },
				this.$slots.default ? this.$slots.default() : [],
			)
		},
	},
	// The dropdown the statuses are offered in. Reproduces only what the
	// dialog depends on: the options it was handed, which of them `selectable`
	// allows, picking one through v-model, and the `#option` slot — the slot
	// matters because that is where a blocked status's reason is rendered, and
	// a stub that dropped it would let the reason silently vanish.
	NcSelect: {
		name: 'NcSelect',
		props: {
			modelValue: { type: Object, default: null },
			options: { type: Array, default: () => [] },
			selectable: { type: Function, default: null },
		},
		emits: ['update:modelValue'],
		render() {
			return h(
				'div',
				this.options.map((option) =>
					h(
						'button',
						{
							key: option.id,
							'data-option': option.label,
							'data-selectable': String(
								this.selectable ? this.selectable(option) : true,
							),
							onClick: () => this.$emit('update:modelValue', option),
						},
						this.$slots.option
							? this.$slots.option(option)
							: String(option.label),
					),
				),
			)
		},
	},
}))

const store = {
	saveObject: vi.fn(),
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

const CASE = {
	id: 'case-1',
	identifier: '2026-0001',
	title: 'Dakkapel Kerkstraat 12',
	caseType: 'ct-1',
	status: 's-1',
}

/** Two workflows sharing status names, which is why columns are merged. */
const STATUS_TYPES = [
	{ id: 's-1', name: 'Ontvangen', caseType: 'ct-1', order: 1 },
	{ id: 's-2', name: 'In behandeling', caseType: 'ct-1', order: 2 },
	{ id: 's-3', name: 'Aanvulling gevraagd', caseType: 'ct-1', order: 3 },
	{ id: 's-4', name: 'Afgehandeld', caseType: 'ct-1', order: 9, isFinal: true },
	{ id: 'o-1', name: 'Ontvangen', caseType: 'ct-2', order: 1 },
	{ id: 'o-2', name: 'Iets heel anders', caseType: 'ct-2', order: 2 },
]

/**
 * Mount the board over the seeded collections and let fetchData settle.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountBoard() {
	fetchCollection.mockImplementation(async (type) => {
		if (type === 'statusType') return STATUS_TYPES
		if (type === 'caseType') return [{ id: 'ct-1', title: 'Bouwvergunning' }]
		if (type === 'case') return [CASE]
		return []
	})

	const wrapper = mount(WorkflowBoard, {
		global: { mocks: { $router: { push: vi.fn(() => Promise.resolve()) } } },
	})
	await flushPromises()
	await wrapper.vm.$nextTick()
	return wrapper
}

const card = (wrapper) => wrapper.find('.case-card')
/**
 * Every status the dialog is offering, and whether it can be picked.
 *
 * @param {object} wrapper The mounted board.
 * @return {Array<{label: string, selectable: string}>} The options, in order.
 */
function options(wrapper) {
	return wrapper.findAll('[data-option]').map((node) => ({
		label: node.attributes('data-option'),
		selectable: node.attributes('data-selectable'),
	}))
}

beforeEach(() => {
	vi.clearAllMocks()
	axios.get.mockResolvedValue({
		data: {
			transitions: [
				{ id: 't1', label: 'Start', toStatus: 's-2' },
				{
					id: 't2',
					label: 'Close',
					toStatus: 's-4',
					guardsPassed: false,
					failedGuards: [{ failureMessage: 'No decision recorded yet' }],
				},
			],
		},
	})
	axios.post.mockResolvedValue({ data: { success: true } })
})

describe('the card no longer carries a move menu', () => {
	it('renders no move control at all', async () => {
		const wrapper = await mountBoard()

		expect(card(wrapper).exists()).toBe(true)
		expect(wrapper.find('.case-card__move-actions').exists()).toBe(false)
	})

	it('announces the M gesture instead, since nothing on screen shows it', async () => {
		const wrapper = await mountBoard()
		expect(card(wrapper).attributes('aria-label')).toContain('M to move')
	})

	it('still opens the case on click, which the menu used to sit beside', async () => {
		const wrapper = await mountBoard()
		await card(wrapper).trigger('click')
		expect(wrapper.vm.$router.push).toHaveBeenCalledWith({
			name: 'CaseDetail',
			params: { id: 'case-1' },
		})
	})
})

describe('asking to move a card', () => {
	it('offers Move on right-click', async () => {
		const wrapper = await mountBoard()
		await card(wrapper).trigger('contextmenu')

		const menu = wrapper.find('[data-testid="cn-context-menu"]')
		expect(menu.exists()).toBe(true)
		expect(menu.findAll('[data-context-action]')).toHaveLength(1)
		expect(menu.find('[data-context-action]').text()).toContain('Move')
	})

	it('reads the engine offer for that case when Move is chosen', async () => {
		const wrapper = await mountBoard()
		await card(wrapper).trigger('contextmenu')
		await wrapper.find('[data-context-action]').trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(
			expect.stringContaining('/case/case-1/available-transitions'),
		)
	})

	it('opens the dialog straight from the M key, no menu in between', async () => {
		const wrapper = await mountBoard()
		await card(wrapper).trigger('keydown', { key: 'm' })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(
			expect.stringContaining('/case/case-1/available-transitions'),
		)
		expect(wrapper.find('[data-testid="move-case-select"]').exists()).toBe(true)
	})
})

describe('what the dialog offers', () => {
	it('lists the offered statuses, not the board columns', async () => {
		const wrapper = await mountBoard()
		// The board merged four columns out of the seeded status types; the
		// engine offers two moves. The dialog must show the two.
		expect(wrapper.vm.columns.length).toBeGreaterThan(2)

		await card(wrapper).trigger('keydown', { key: 'm' })
		await flushPromises()

		expect(options(wrapper).map((option) => option.label)).toEqual([
			'In behandeling',
			'Afgehandeld',
		])
	})

	it('offers a blocked status but refuses to let it be picked', async () => {
		const wrapper = await mountBoard()
		await card(wrapper).trigger('keydown', { key: 'm' })
		await flushPromises()

		const blocked = options(wrapper).find(
			(option) => option.label === 'Afgehandeld',
		)
		expect(blocked.selectable).toBe('false')
		expect(wrapper.text()).toContain('No decision recorded yet')
	})

	it('says so when the offer could not be read, rather than showing nothing', async () => {
		axios.get.mockRejectedValue(new Error('Network Error'))
		const wrapper = await mountBoard()
		await card(wrapper).trigger('keydown', { key: 'm' })
		await flushPromises()

		expect(wrapper.vm.moveTargetsError).toContain('Network Error')
		expect(wrapper.find('[data-testid="move-case-select"]').exists()).toBe(
			false,
		)
	})

	it('says when a case has nowhere to go', async () => {
		axios.get.mockResolvedValue({ data: { transitions: [] } })
		const wrapper = await mountBoard()
		await card(wrapper).trigger('keydown', { key: 'm' })
		await flushPromises()

		expect(wrapper.vm.moveTargets).toEqual([])
		expect(wrapper.text()).toContain('nowhere to go')
	})
})

describe('confirming the move', () => {
	it('posts the transition the engine offered, through the drag path', async () => {
		const wrapper = await mountBoard()
		await card(wrapper).trigger('keydown', { key: 'm' })
		await flushPromises()

		await wrapper.find('[data-option="In behandeling"]').trigger('click')
		await wrapper.find('[data-testid="move-case-confirm"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/case/case-1/transition'),
			expect.objectContaining({ transitionId: 't1' }),
		)
		// The write path, not a direct field write: that distinction is the
		// whole reason the board asks the engine at all.
		expect(store.saveObject).not.toHaveBeenCalled()
	})

	it('moves the card to the chosen column', async () => {
		const wrapper = await mountBoard()
		// The re-read `onDrop` runs after a successful move would regroup from
		// the mocked collection, which still reports the old status, and
		// overwrite the optimistic card before the assertion reads it.
		// `workflowBoardMove.spec.js` stubs it for the same reason.
		wrapper.vm.fetchData = vi.fn(async () => {})
		await card(wrapper).trigger('keydown', { key: 'm' })
		await flushPromises()

		await wrapper.find('[data-option="In behandeling"]').trigger('click')
		await wrapper.find('[data-testid="move-case-confirm"]').trigger('click')
		await flushPromises()

		expect(
			wrapper.vm.casesByStatus['In behandeling'].map((c) => c.id),
		).toContain('case-1')
		expect(wrapper.vm.casesByStatus.Ontvangen.map((c) => c.id)).not.toContain(
			'case-1',
		)
	})

	it('posts nothing until a status is picked', async () => {
		const wrapper = await mountBoard()
		await card(wrapper).trigger('keydown', { key: 'm' })
		await flushPromises()

		const confirm = wrapper.find('[data-testid="move-case-confirm"]')
		expect(confirm.attributes('disabled')).toBeDefined()
		await confirm.trigger('click')
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()
	})
})
