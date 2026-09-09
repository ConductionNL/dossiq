// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Renders the comparison section of `FeaturesRoadmapView.vue` and checks that
 * the four things the page is not allowed to drop are still on the screen.
 *
 * Those four caveats already have e2e coverage in `tests/e2e/app-chrome.spec.ts`,
 * and that coverage needs a running Nextcloud. This suite needs a DOM and
 * nothing else, so a caveat that falls out of the template fails in seconds on
 * a laptop instead of at the end of a CI run. The failure mode is a real one:
 * every caveat is a plain `<p>` inside a note card, and deleting one while
 * editing the panel around it costs nothing and breaks nothing visible.
 *
 * `CnFeaturesAndRoadmapPage` is stubbed: it is the library's own component,
 * it belongs to the product section rather than the comparison, and it pulls
 * a Nextcloud runtime in behind it.
 *
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-comparison-must-state-its-own-limits
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-present-the-capability-comparison-by-area
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

// Both component libraries are replaced BEFORE the view's module graph
// loads. Importing `@nextcloud/vue` for real drags in NcRichContenteditable,
// which calls `imagePath()` from `@nextcloud/router` at import time and
// throws without a Nextcloud runtime. Neither library renders any part of
// what this suite asserts: the caveats and the totals table are this view's
// own template.
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		render() {
			return h('button', this.$slots.default?.())
		},
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type', 'heading'],
		render() {
			return h('div', this.$slots.default?.())
		},
	},
}))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnFeaturesAndRoadmapPage: {
		name: 'CnFeaturesAndRoadmapPage',
		render() {
			return h('div')
		},
	},
}))

const { default: data } = await import(
	'../../src/data/capabilityComparison.json'
)
const { default: FeaturesRoadmapView } = await import(
	'../../src/views/FeaturesRoadmapView.vue'
)

/**
 * Mount the view and switch it to the comparison section.
 *
 * @return {object} The mounted wrapper.
 */
async function mountComparison() {
	const wrapper = mount(FeaturesRoadmapView)
	await wrapper.setData({ section: 'comparison' })
	return wrapper
}

describe('FeaturesRoadmapView comparison caveats', () => {
	it('keeps all four mandatory caveats on the page', async () => {
		const text = (await mountComparison()).text()

		// 1. Only open source we could install and run ourselves.
		expect(text).toContain('open source software we could install and run')
		// 2. The reading date, and that it goes stale.
		expect(text).toContain('already out of date')
		// 3. A rating is our reading, not proof.
		expect(text).toContain('is not proof that a product does')
		// 4. Run your own evaluation.
		expect(text).toContain('run your own evaluation')
	})

	it('dates the reading in the reader-s own language', async () => {
		const text = (await mountComparison()).text()
		expect(text).toContain('September 7, 2026')
	})

	it('says which rows a later round added, and that rivals are unrated', async () => {
		const text = (await mountComparison()).text()
		const added = data.capabilities.filter((row) => row.addedOn).length

		expect(text).toContain(`we added ${added} capabilities`)
		expect(text).toContain('a guessed rating is worse than an empty cell')
	})

	it('shows Unknown as a column in the totals, not as a silent gap', async () => {
		const wrapper = await mountComparison()
		const headers = wrapper
			.findAll('.features-roadmap__table thead th')
			.map((th) => th.text())

		expect(headers).toContain('Unknown')

		// The three competitor columns are unrated on exactly the added rows,
		// so their Unknown cell has to carry that number rather than a zero.
		const added = data.capabilities.filter((row) => row.addedOn).length
		const rows = wrapper.findAll('.features-roadmap__table tbody tr')
		const opencase = rows.find((row) => row.text().startsWith('OpenCase'))
		expect(opencase.findAll('td').at(3).text()).toBe(String(added))
	})
})
