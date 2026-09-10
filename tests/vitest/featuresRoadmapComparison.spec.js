// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Renders the comparison section of `FeaturesRoadmapView.vue` and checks that
 * the five things the page is not allowed to drop are still on the screen.
 *
 * Those five caveats already have e2e coverage in `tests/e2e/app-chrome.spec.ts`,
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

const { default: data } = await import('../../src/data/capabilityComparison.json')
const { default: FeaturesRoadmapView } =
	await import('../../src/views/FeaturesRoadmapView.vue')

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
	it('keeps all five mandatory caveats on the page', async () => {
		const text = (await mountComparison()).text()

		// 1. Only open source we could install and run ourselves.
		expect(text).toContain('open source software we could install and run')
		// 2. The reading date, and that it goes stale.
		expect(text).toContain('already out of date')
		// 3. A rating is our reading, not proof.
		expect(text).toContain('is not proof that a product does')
		// 4. Run your own evaluation.
		expect(text).toContain('run your own evaluation')
		// 5. A column whose product owns no data scores low for a structural
		//    reason, and that reason runs in our favour.
		expect(text).toContain('owns no data')
		expect(text).toContain('which flatters us')
	})

	it('names the column that owns no data, and the register it defers to', async () => {
		const text = (await mountComparison()).text()
		const deferring = data.systems.filter((system) => system.ownsNoData)

		// The caveat is worthless as a generic disclaimer. It has to name the
		// column a reader is about to compare against, and the product that
		// holds the record instead, or they cannot act on it.
		expect(deferring.length).toBeGreaterThan(0)
		for (const system of deferring) {
			expect(text).toContain(system.name)
			expect(text).toContain(system.ownsNoData)
		}
	})

	it('puts the column caveat above the first score, not after it', async () => {
		// A caveat a reader meets after the totals is a caveat they meet too
		// late. This is the assertion that fails if the note card is ever
		// moved below the table while its wording survives intact.
		const text = (await mountComparison()).text()
		expect(text.indexOf('owns no data')).toBeGreaterThan(-1)
		expect(text.indexOf('owns no data')).toBeLessThan(
			text.indexOf('Totals over all'),
		)
	})

	it('dates a column read on its own day, without moving the shared date', async () => {
		const text = (await mountComparison()).text()
		const late = data.systems.filter((system) => system.readOn)

		expect(late.length).toBeGreaterThan(0)
		// The shared sentence now counts the systems it actually covers, so a
		// fifth column read a day later cannot make it claim the fifth.
		expect(text).toContain(
			`We read ${data.systems.length - late.length} of the ${data.systems.length} systems`,
		)
		expect(text).toContain('not on the date above')
	})

	it('dates the reading in the reader-s own language', async () => {
		const text = (await mountComparison()).text()
		expect(text).toContain('September 7, 2026')
	})

	it('counts only ratings it moved as corrections, not rows it added', async () => {
		const text = (await mountComparison()).text()
		const moved = data._rerated.filter((entry) => entry.from !== null)
		const added = data._rerated.filter((entry) => entry.from === null)

		// `_rerated` is one log with two kinds of entry in it, and the panel
		// used to count the whole log: it claimed 25 corrections where 6 were
		// made, and the other 19 were the added rows the next paragraph
		// already reports. Overstating our own diligence on the page that
		// exists to caveat itself is the one direction to get this wrong in.
		expect(added.length).toBeGreaterThan(0)
		expect(text).toContain(`We corrected ${moved.length} of our own ratings`)
		expect(text).not.toContain(
			`We corrected ${data._rerated.length} of our own ratings`,
		)
	})

	it('says which rows a later round added, and that rivals are unrated', async () => {
		const text = (await mountComparison()).text()
		const added = data.capabilities.filter((row) => row.addedOn).length

		expect(text).toContain(`we added ${added} capabilities`)
		expect(text).toContain('a guessed rating is worse than an empty cell')
	})

	it('does not date every added row to the most recent round', async () => {
		// The failure this catches, which is the one that actually happened.
		// The sentence read "On {date} we added {count}" and was true while a
		// single round had ever added rows. A second round made it a lie about
		// the first round's 19: they were asked a day earlier, and the page
		// would have said otherwise while every test went on passing.
		const text = (await mountComparison()).text()
		const dates = data.capabilities
			.filter((row) => row.addedOn)
			.map((row) => row.addedOn)
		const latest = [...dates].sort().at(-1)
		const onLatest = dates.filter((date) => date === latest).length

		// Only meaningful while more than one round has added rows. If a
		// future compaction ever collapses them, this says so out loud rather
		// than passing vacuously.
		expect(new Set(dates).size).toBeGreaterThan(1)
		expect(onLatest).toBeLessThan(dates.length)

		// The page names the most recent date and the total, and must not
		// glue them together into a claim that they all landed that day.
		expect(text).toContain('the most recent of them on')
		expect(text).not.toContain(`On ${latest} we added ${dates.length}`)
	})

	it('shows Unknown as a column in the totals, not as a silent gap', async () => {
		const wrapper = await mountComparison()
		const headers = wrapper
			.findAll('.features-roadmap__table thead th')
			.map((th) => th.text())

		expect(headers).toContain('Unknown')

		// Every competitor column is unrated on exactly the added rows, so
		// each Unknown cell has to carry that number rather than a zero. That
		// now includes the column added last: it was read before those rows
		// existed, so it is as empty on them as the other three.
		const added = data.capabilities.filter((row) => row.addedOn).length
		const rows = wrapper.findAll('.features-roadmap__table tbody tr')
		for (const system of data.systems.filter((s) => !s.isSelf)) {
			const row = rows.find((r) => r.text().startsWith(system.name))
			expect(row, system.key).toBeTruthy()
			expect(row.findAll('td').at(3).text(), system.key).toBe(String(added))
		}
	})
})
