// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The search index panel shows what openregister said, and nothing else.
 *
 * 🔴 AN OUTAGE AND AN INSTANCE WITH NO INDEXES LOOK THE SAME AS FIGURES.
 * Both would draw "0 tables, 0 indexes, never run", and only one of them is
 * somebody's problem. So a failed read draws the failure and never a table of
 * zeroes, which is the assertion that keeps the panel honest.
 *
 * 🔴 IT KEEPS NO COPY. Everything on the panel comes from
 * `GET /apps/openregister/api/settings/search-index` on the fetch. A remembered
 * figure is one an administrator can read while the real indexes are missing.
 *
 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

/**
 * A stand-in for a Nextcloud component that renders its slot.
 *
 * @param {string} name The component name.
 * @return {object} The stub component.
 */
function stub(name) {
	return defineComponent({
		name,
		props: ['type', 'size'],
		render() {
			return h(
				'div',
				{ class: `${name}-stub`, 'data-type': this.type },
				this.$slots.default?.(),
			)
		},
	})
}

vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({
	default: stub('NcLoadingIcon'),
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({
	default: stub('NcNoteCard'),
}))

/** What openregister answers. Replaced per test. */
let answer = () => ({})

vi.mock('../../src/services/searchIndexApi.js', () => ({
	SEARCH_INDEX_COMMAND: 'occ openregister:tables:search-index',
	searchIndexStatus: () =>
		Promise.resolve(answer()).then((value) => {
			if (value instanceof Error) {
				throw value
			}
			return value
		}),
}))

const { default: SearchIndexTab } =
	await import('../../src/views/settings/tabs/SearchIndexTab.vue')

const HEALTHY = {
	concurrentRebuildSupported: true,
	tables: {
		dossiq_case: ['idx_case_search'],
		dossiq_beroep: ['idx_beroep_search'],
	},
	tableCount: 2,
	indexCount: 2,
	lastRun: '2026-09-15T22:00:00+00:00',
}

/**
 * Mount the panel over a given answer.
 *
 * @return {Promise<object>} The mounted wrapper, after the fetch settled.
 */
async function mountPanel() {
	const wrapper = mount(SearchIndexTab, {
		global: { mocks: { t: (_app, text) => text } },
	})
	await flushPromises()

	return wrapper
}

beforeEach(() => {
	answer = () => HEALTHY
})

describe('SearchIndexTab', () => {
	it('shows the figures openregister answered with', async () => {
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="search-index-tables"]').text()).toBe('2')
		expect(wrapper.find('[data-testid="search-index-indexes"]').text()).toBe('2')
		expect(wrapper.find('[data-testid="search-index-last-run"]').text()).toBe(
			'2026-09-15T22:00:00+00:00',
		)
	})

	it('says a rebuild is safe when the platform can do it concurrently', async () => {
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="search-index-concurrent"]').text()).toBe(
			'Yes',
		)
	})

	it('says a rebuild locks the table when the platform cannot do it concurrently', async () => {
		answer = () => ({ ...HEALTHY, concurrentRebuildSupported: false })
		const wrapper = await mountPanel()

		expect(
			wrapper.find('[data-testid="search-index-concurrent"]').text(),
		).toContain('a rebuild locks the table')
	})

	it('says never rather than nothing when maintenance has not run', async () => {
		answer = () => ({ ...HEALTHY, lastRun: null })
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="search-index-last-run"]').text()).toBe(
			'Never',
		)
	})

	it('names the occ command that acts on the indexes', async () => {
		const wrapper = await mountPanel()

		expect(
			wrapper.find('[data-testid="search-index-commands"]').text(),
		).toContain('occ openregister:tables:search-index')
	})

	it('draws the failure rather than a table of zeroes', async () => {
		answer = () => new Error('The search index status could not be read.')
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="search-index-error"]').text()).toBe(
			'The search index status could not be read.',
		)
		expect(wrapper.find('[data-testid="search-index-tables"]').exists()).toBe(
			false,
		)
	})
})
