// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The case sidebar's Tasks tab must create a task through the Tasks index.
 *
 * The defect this pins down: "New task" pushed the `TaskNew` page, a
 * `type: detail` page at `/tasks/new`. CnDetailPage read the object id off
 * the route, fetched the object "new", got nothing and rendered an empty
 * page. The page is gone; the tab now opens the Tasks index with
 * `?action=create` (which CnIndexPage turns into its create dialog) and
 * `case=<id>` so the list behind the dialog stays scoped to this case.
 * The tab's own fetch is asserted too: it filters on a bare `case` key,
 * because the `_filters[case]` form it once used was inert and listed every
 * case's tasks.
 *
 * @spec openspec/specs/task-management/spec.md#requirement-task-list-must-be-reached-via-mijn-werk-not-a-sibling-top-level-menu
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnStatusBadge: defineComponent({
		name: 'CnStatusBadge',
		props: ['status', 'type'],
		render() {
			return h('span', { class: 'status-badge-stub' }, this.status)
		},
	}),
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: defineComponent({
		name: 'NcButton',
		emits: ['click'],
		render() {
			return h(
				'button',
				{ class: 'nc-button-stub', onClick: () => this.$emit('click') },
				this.$slots.default ? this.$slots.default() : [],
			)
		},
	}),
	NcEmptyContent: defineComponent({
		name: 'NcEmptyContent',
		props: ['title', 'description'],
		render() {
			return h('div', { class: 'empty-content-stub' }, this.title)
		},
	}),
	NcLoadingIcon: defineComponent({
		name: 'NcLoadingIcon',
		render() {
			return h('div', { class: 'loading-stub' })
		},
	}),
}))

vi.mock('../../src/store/store.js', () => ({
	initializeStores: vi.fn(() => Promise.resolve()),
}))

const fetchCollection = vi.fn()
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({ fetchCollection }),
}))

// Imported AFTER the mocks so the component sees the stubbed modules.
const { default: CaseTasksTab } =
	await import('../../src/components/tabs/CaseTasksTab.vue')

/**
 * Mount the tab with a spy router and an optional route fallback.
 *
 * @param {object} options Mount options.
 * @param {string|null} [options.objectId] The sidebar's shared objectId prop.
 * @param {object} [options.routeParams] `$route.params` for the standalone fallback.
 * @return {{ wrapper: object, push: Function }} The wrapper and the router spy.
 */
async function mountTab({ objectId = 'case-1', routeParams = {} } = {}) {
	const push = vi.fn()
	const wrapper = mount(CaseTasksTab, {
		props: { objectId },
		global: {
			mocks: {
				$router: { push },
				$route: { params: routeParams },
			},
		},
	})
	await flushPromises()
	return { wrapper, push }
}

describe('CaseTasksTab', () => {
	beforeEach(() => {
		fetchCollection.mockReset()
		fetchCollection.mockResolvedValue([])
	})

	it('opens the Tasks index create dialog scoped to this case, not a TaskNew page', async () => {
		const { wrapper, push } = await mountTab()
		await wrapper.find('.nc-button-stub').trigger('click')
		expect(push).toHaveBeenCalledTimes(1)
		expect(push).toHaveBeenCalledWith({
			name: 'Tasks',
			query: { action: 'create', case: 'case-1' },
		})
		expect(push.mock.calls[0][0].name).not.toBe('TaskNew')
	})

	it('falls back to the route id when the sidebar passes no objectId', async () => {
		const { wrapper, push } = await mountTab({
			objectId: null,
			routeParams: { id: 'case-from-route' },
		})
		await wrapper.find('.nc-button-stub').trigger('click')
		expect(push).toHaveBeenCalledWith({
			name: 'Tasks',
			query: { action: 'create', case: 'case-from-route' },
		})
	})

	it("lists only this case's tasks, filtered on the bare case key", async () => {
		fetchCollection.mockResolvedValue([
			{ id: 't1', title: 'Call the requester', status: 'open' },
			{ id: 't2', title: 'File the decision', status: 'completed' },
		])
		const { wrapper } = await mountTab()
		expect(fetchCollection).toHaveBeenCalledWith('caseTask', {
			case: 'case-1',
			_limit: 50,
		})
		expect(fetchCollection.mock.calls[0][1]).not.toHaveProperty('_filters')
		expect(wrapper.findAll('.case-tab__item')).toHaveLength(2)
		expect(wrapper.find('.case-tab__count').text()).toBe('(1/2)')
	})

	it('opens a task on its detail page', async () => {
		fetchCollection.mockResolvedValue([
			{ id: 't1', title: 'Call the requester', status: 'open' },
		])
		const { wrapper, push } = await mountTab()
		await wrapper.find('.case-tab__item').trigger('click')
		expect(push).toHaveBeenCalledWith({
			name: 'TaskDetail',
			params: { id: 't1' },
		})
	})
})
