// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The case type list on the admin settings page heads itself one level under
 * its section.
 *
 * The list sits inside the "Case type management" section, whose name is an
 * `<h2>`. `CnIndexPage` draws its title as an `<h1>` unless the host fills its
 * `#header` slot, so the page carried an `<h1>` inside an `<h2>` section.
 *
 * The vitest suite stubs `@conduction/nextcloud-vue`, so the stub below does
 * what the library does (an `<h1>` unless `#header` is filled), and the second
 * test reads the library's own source to pin that this is still the rule.
 *
 * @spec openspec/changes/r6-dossiq-titles-related-cases-requests/specs/admin-settings/spec.md
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
			addLabel: { type: String, default: '' },
		},
		setup(props) {
			indexPageProps.current = props
		},
		render() {
			const header = this.$slots.header
				? this.$slots.header({ title: this.title })
				: [h('h1', {}, this.title)]
			return h('div', { class: 'cn-index-page-stub' }, header)
		},
	}),
}))

vi.mock('@nextcloud/vue', () => {
	const plain = (name, tag = 'span') =>
		defineComponent({
			name,
			render() {
				return h(tag, {}, this.$slots.default ? this.$slots.default() : [])
			},
		})
	return {
		NcButton: plain('NcButton', 'button'),
		NcLoadingIcon: plain('NcLoadingIcon'),
		NcEmptyContent: plain('NcEmptyContent', 'div'),
	}
})

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

const { default: CaseTypeList } =
	await import('../../src/views/settings/CaseTypeList.vue')

const here = dirname(fileURLToPath(import.meta.url))
const LIB = resolve(here, '../../node_modules/@conduction/nextcloud-vue/src/components')

describe('CaseTypeList heading', () => {
	it('heads the list with an h3 under its h2 section, never an h1', async () => {
		const wrapper = mount(CaseTypeList, {
			global: { mocks: { t: (_app, text) => text } },
		})
		await flushPromises()

		expect(wrapper.find('h1').exists()).toBe(false)
		const heading = wrapper.find('[data-testid="case-type-list-heading"]')
		expect(heading.exists()).toBe(true)
		expect(heading.element.tagName).toBe('H3')
		expect(heading.text()).toBe('Case types')

		wrapper.unmount()
	})

	it('labels the Add button in sentence case', async () => {
		const wrapper = mount(CaseTypeList, {
			global: { mocks: { t: (_app, text) => text } },
		})
		await flushPromises()

		expect(indexPageProps.current.addLabel).toBe('Add case type')

		wrapper.unmount()
	})

	it('relies on a rule the library still has: without #header the title is an h1', () => {
		const indexPage = readFileSync(resolve(LIB, 'CnIndexPage/CnIndexPage.vue'), 'utf8')
		const pageHeader = readFileSync(resolve(LIB, 'CnPageHeader/CnPageHeader.vue'), 'utf8')

		expect(indexPage).toMatch(/<slot\s+name="header"[\s\S]*?<CnPageHeader/)
		expect(pageHeader).toMatch(/<h1 class="cn-page-header__title"/)
	})
})
