// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A row in My Work's table view opens its case.
 *
 * THE DEFECT THIS PINS DOWN
 * -------------------------
 * `MyWorkCards` renders `CnIndexPage`, which is selectable by default, and
 * listens for the row click. `CnIndexPage.onRowClick` only emits `row-click`
 * when the page is not selectable or when `rowClickToView` is set; otherwise
 * it ticks the row's checkbox and returns. So in the Table view a click on an
 * assigned case selected it and went nowhere. Measured on a running instance:
 * the URL stayed on /my-work and one checkbox was ticked. The Cards view was
 * never affected, because the page's own card component emits `open` itself.
 *
 * The same shape as the admin case type list (see
 * `caseTypeListRowClick.spec.js`, which also checks the library rule both
 * rely on). Here the rule is checked again rather than imported, so this file
 * stands on its own.
 *
 * @spec openspec/specs/my-work/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const indexPageProps = {}

vi.mock('@conduction/nextcloud-vue', () => ({
	CnIndexPage: defineComponent({
		name: 'CnIndexPage',
		props: {
			selectable: { type: Boolean, default: true },
			rowClickToView: { type: Boolean, default: false },
		},
		emits: ['row-click', 'view'],
		setup(props) {
			indexPageProps.current = props
		},
		render() {
			return h('div', { class: 'cn-index-page-stub' })
		},
	}),
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: defineComponent({
		name: 'NcButton',
		render() {
			return h('button', {}, this.$slots.default ? this.$slots.default() : [])
		},
	}),
}))

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'admin' }),
}))

vi.mock('../../src/store/store.js', () => ({
	initializeStores: vi.fn(() => Promise.resolve()),
}))

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		fetchCollection: vi.fn(() => Promise.resolve([])),
	}),
}))

const { default: MyWorkCards } = await import('../../src/views/MyWorkCards.vue')

/** The library's own index page, as shipped in node_modules. */
const CN_INDEX_PAGE_SOURCE = resolve(
	dirname(fileURLToPath(import.meta.url)),
	'../../node_modules/@conduction/nextcloud-vue/src/components/CnIndexPage/CnIndexPage.vue',
)

/**
 * Mount My Work with a spy router.
 *
 * @return {{wrapper: object, push: Function}} The wrapper and the router spy.
 */
async function mountMyWork() {
	const push = vi.fn()
	const wrapper = mount(MyWorkCards, {
		global: {
			mocks: { $router: { push } },
			stubs: { MyWorkCaseCard: true, WorkloadSummaryBar: true },
		},
	})
	await flushPromises()
	return { wrapper, push }
}

describe('My Work row click', () => {
	it('asks the index page for navigation on a row click, not only for selection', async () => {
		const { wrapper } = await mountMyWork()

		const props = indexPageProps.current
		expect(props, 'MyWorkCards should render CnIndexPage').toBeTruthy()
		// Not passed, so the library default: selectable. Stated so a change
		// of default upstream shows up here rather than on the page.
		expect(props.selectable).toBe(true)
		expect(
			props.rowClickToView,
			'a selectable index page without rowClickToView turns a row click into a checkbox tick',
		).toBe(true)

		wrapper.unmount()
	})

	it('relies on a rule the library still has: selectable without rowClickToView only selects', () => {
		const source = readFileSync(CN_INDEX_PAGE_SOURCE, 'utf8')
		const handler = source.slice(
			source.indexOf('onRowClick(row) {'),
			source.indexOf("this.$emit('row-click', row)"),
		)

		expect(handler, 'CnIndexPage.onRowClick should still exist').not.toBe('')
		expect(handler).toMatch(
			/if \(this\.selectable && !this\.rowClickToView\) \{\s*this\.onSelect\([^\n]*\)\s*return\s*\}/,
		)
	})

	it('opens the case the row carries', async () => {
		const { wrapper, push } = await mountMyWork()

		wrapper
			.findComponent({ name: 'CnIndexPage' })
			.vm.$emit('row-click', { id: 'case-7', title: 'Bezwaar tegen besluit' })

		expect(push).toHaveBeenCalledWith({
			name: 'CaseDetail',
			params: { id: 'case-7' },
		})

		wrapper.unmount()
	})
})
