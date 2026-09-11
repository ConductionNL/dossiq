// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Clicking a case type in the admin list opens it.
 *
 * THE DEFECT THIS PINS DOWN
 * -------------------------
 * `CaseTypeList` hands `CnIndexPage` `:selectable="true"` and listens for the
 * row click. `CnIndexPage.onRowClick` only emits `row-click` when the page is
 * NOT selectable, or when `rowClickToView` is set; a selectable page without
 * it TOGGLES THE ROW'S CHECKBOX instead and returns. So a click on a case type
 * selected it, `selectCaseType` never ran, and `CaseTypeDetail` was never
 * mounted: no administrator could open an existing case type on the admin
 * page, which is the only place `StatusesTab` lives. The Statuses tab that
 * `case-type-authored-not-edited` built was unreachable. Nothing errored; the
 * row simply turned grey. Found by `tests/e2e/case-type-status-authoring.spec.ts`,
 * which clicked the row and waited five minutes for a tab that never came.
 *
 * HOW IT IS TESTED WITHOUT TESTING A STUB
 * ---------------------------------------
 * The vitest suite aliases `@conduction/nextcloud-vue` to a stub, so a
 * stubbed index page that emitted `row-click` on every click would pass with
 * the defect in place. Two things are asserted instead, and together they
 * are the chain:
 *
 *  1. the props `CaseTypeList` actually hands the index page ask for
 *     navigation (`rowClickToView`) and not only for selection;
 *  2. the library's OWN `onRowClick`, read from its source, still makes that
 *     the rule. Importing the real SFC is not possible here (its imports pull
 *     `@nextcloud/vue` CSS the transform pipeline cannot externalise), so the
 *     rule is checked as text. If the library ever changes it, this reddens
 *     and says which half moved, rather than the admin list quietly going
 *     inert again.
 *
 * @spec openspec/specs/case-types/spec.md
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
			title: { type: String, default: '' },
			description: { type: String, default: '' },
			schema: { type: Object, default: null },
			objects: { type: Array, default: () => [] },
			loading: { type: Boolean, default: false },
			selectable: { type: Boolean, default: true },
			rowClickToView: { type: Boolean, default: false },
		},
		emits: ['add', 'refresh', 'row-click'],
		setup(props) {
			// Keep the live props object, so the test reads what was passed
			// at the moment it looks rather than a copy from mount time.
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
	NcLoadingIcon: defineComponent({
		name: 'NcLoadingIcon',
		render() {
			return h('span')
		},
	}),
}))

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		loading: {},
		collections: { caseType: [] },
		fetchSchema: vi.fn(() => Promise.resolve(null)),
		fetchCollection: vi.fn(() => Promise.resolve([])),
	}),
}))

vi.mock('../../src/store/modules/settings.js', () => ({
	useSettingsStore: () => ({ config: {} }),
}))

// Imported AFTER the mocks so the component sees the stubbed modules.
const { default: CaseTypeList } =
	await import('../../src/views/settings/CaseTypeList.vue')

/** The library's own index page, as shipped in node_modules. */
const CN_INDEX_PAGE_SOURCE = resolve(
	dirname(fileURLToPath(import.meta.url)),
	'../../node_modules/@conduction/nextcloud-vue/src/components/CnIndexPage/CnIndexPage.vue',
)

describe('CaseTypeList row click', () => {
	it('asks the index page for navigation on a row click, not only for selection', async () => {
		const wrapper = mount(CaseTypeList)
		await flushPromises()

		const props = indexPageProps.current
		expect(props, 'CaseTypeList should render CnIndexPage').toBeTruthy()

		// Selectable stays: the checkboxes are how several case types are
		// picked at once. What must come with it is navigation on a click.
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

	it('turns the row click into the select event that opens the detail', async () => {
		const wrapper = mount(CaseTypeList)
		await flushPromises()

		wrapper
			.findComponent({ name: 'CnIndexPage' })
			.vm.$emit('row-click', { id: 'ct-1', title: 'Bezwaar' })

		expect(wrapper.emitted('select')).toEqual([['ct-1']])

		wrapper.unmount()
	})
})
