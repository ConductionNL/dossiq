/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A hidden status is hidden everywhere work is counted (REQ-LIFE-01).
 *
 * `statusType.hiddenInLists` reaches a case as the calculated mirror
 * `case.statusHiddenInLists`, and until this change exactly one place read
 * it: the All chip on the Cases index. So an administrator who marked a
 * status hidden emptied one list and left the same cases filling Overdue,
 * Due this week, Mine, Unclaimed, the Queue, My Work, the open count and the
 * dashboard tiles. A list and a count that disagree, with nothing on either
 * to say which is right.
 *
 * This spec enumerates the surfaces rather than asserting one of them,
 * because the defect was never in the mechanism. The flag worked. What was
 * missing was a filter on the other seven readers, and only a list of
 * readers can fail when an eighth is added without one.
 *
 * 🔑 THE TWO DELIBERATE EXCEPTIONS ARE ASSERTED AS EXCEPTIONS, not skipped.
 * Closed is the lens a person explicitly filtered onto the terminal
 * statuses, and a hidden status is usually one of those; narrowing it would
 * empty the only list that is meant to hold them. Completed this month is
 * the same reading on the dashboard. Asserting their absence is what stops
 * somebody later adding the filter there in the belief it was an oversight.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const kpiSource = fs.readFileSync(
	path.join(ROOT, 'lib', 'Service', 'KpiAggregationService.php'),
	'utf8',
)
const myWorkSource = fs.readFileSync(
	path.join(ROOT, 'src', 'views', 'MyWorkCards.vue'),
	'utf8',
)

/** The mirror every working surface narrows on. */
const FLAG = 'statusHiddenInLists'

/**
 * One page as the manifest declares it.
 *
 * @param {string} id The manifest page id.
 * @return {object} The page entry.
 */
function page(id) {
	return manifest.pages.find((entry) => entry.id === id)
}

/**
 * One quick-filter chip of a page.
 *
 * @param {string} pageId The manifest page id.
 * @param {string} label The chip label.
 * @return {object} The chip entry.
 */
function chip(pageId, label) {
	return page(pageId).config.quickFilters.find((entry) => entry.label === label)
}

/**
 * One widget of a dashboard page.
 *
 * @param {string} pageId The manifest page id.
 * @param {string} widgetId The widget id.
 * @return {object} The widget entry.
 */
function widget(pageId, widgetId) {
	return page(pageId).config.widgets.find((entry) => entry.id === widgetId)
}

describe('a hidden status leaves every working lens', () => {
	// The chips that list work somebody is expected to do. Closed is absent
	// on purpose and has its own assertion below.
	it.each(['All', 'Unread', 'Mine', 'Unclaimed', 'Overdue', 'Due this week'])(
		'the %s chip narrows on the hidden mirror',
		(label) => {
			expect(chip('Cases', label).filter[FLAG]).toBe(false)
		},
	)

	it('the Queue page narrows on it too, and stays equal to Unclaimed', () => {
		// Equal, not merely both-filtered. The two answer the same question
		// and nothing at runtime compares them, so they drift the moment one
		// side is edited.
		expect(page('Queue').config.filter[FLAG]).toBe(false)
		expect(chip('Cases', 'Unclaimed').filter).toEqual(
			page('Queue').config.filter,
		)
	})

	it('My Work narrows on it in the base filter the wrapper injects', () => {
		// My Work is a custom view rather than a manifest filter, because the
		// stock index base-filter cannot resolve `@me`. The condition
		// therefore has to be asserted against the source, and the assertion
		// is on the RETURNED filter object rather than on the file containing
		// the word: the word appears in the comment above it either way.
		expect(myWorkSource).toContain(
			`return { assignee: uid, ${FLAG}: false, isDraft: false }`,
		)
	})
})

describe('the counts agree with the lists', () => {
	it('the dashboard open-work counts carry the condition', () => {
		// One constant, spread into the three counts that answer over the same
		// population. Asserted as the constant rather than three call sites,
		// because that is the shape that cannot drift.
		expect(kpiSource).toContain(
			"private const OPEN_WORK = ['isFinalStatus' => 0, "
				+ "'statusHiddenInLists' => 0, 'isDraft' => 0];",
		)
		expect(kpiSource).toContain('filters: self::OPEN_WORK')
		expect(kpiSource).toContain(
			'filters: (self::OPEN_WORK + [\'deadline\' => [\'lt\' => $today]])',
		)
	})

	it('the open-work counts are spelled 0 and not false', () => {
		// A PHP bool reaches PostgreSQL as a type it will not compare against
		// the stored JSON and the query throws, which is a red tile rather
		// than a wrong number. Guarding the spelling is cheap.
		expect(kpiSource).not.toContain("'statusHiddenInLists' => false")
	})

	it('the Overdue tile asks for exactly what the Overdue chip asks for', () => {
		// `kpi-overdue` is the tile whose count the chip reproduces. Its
		// route query is the bracket-key spelling of the same filter, so a
		// condition added to one has to reach the other or the reader lands
		// on a list that does not contain the number they clicked.
		const query = widget('Dashboard', 'kpi-overdue').content.route.query
		expect(query[FLAG]).toBe('false')
		expect(query.isFinalStatus).toBe('false')
	})

	it.each(['cases-by-status', 'cases-by-type'])(
		'the %s chart counts open work only',
		(id) => {
			expect(
				widget('Dashboard', id).content.dataSource.filter[FLAG],
			).toBe(false)
		},
	)

	it.each([
		['Dashboard', 'stalled-cases'],
		['MyWorkHome', 'deadlines'],
		['MyWorkHome', 'open-cases'],
	])('the %s / %s table narrows on it', (pageId, id) => {
		expect(widget(pageId, id).content.source.filter[FLAG]).toBe(false)
	})
})

describe('hidden never means unfindable', () => {
	it('the Closed lens deliberately carries no hidden narrowing', () => {
		// Asserted as an absence on purpose. A hidden status is usually a
		// terminal one, so filtering here would empty the one list that is
		// supposed to hold them, and a person on the Closed chip has named
		// the statuses they want.
		expect(Object.hasOwn(chip('Cases', 'Closed').filter, FLAG)).toBe(false)
	})

	it('the Completed this month tile deliberately carries none either', () => {
		const query = widget('Dashboard', 'kpi-completed').content.route.query
		expect(Object.hasOwn(query, FLAG)).toBe(false)
	})

	it('no search or detail surface narrows on it', () => {
		// The rule is about the WORKING list. A case somebody cannot find is
		// a different and worse bug than a case in a list they did not want,
		// so the flag must not reach the case page or the search config.
		const detail = JSON.stringify(page('CaseDetail'))
		expect(detail).not.toContain(FLAG)
	})

	it('the scan can see the flag at all', () => {
		// Without this the four absence assertions above would pass on a
		// misspelled key, a wrong page id, or a manifest that lost the flag
		// entirely, and an absence that proves nothing reads exactly like an
		// absence that proves something.
		const hits = JSON.stringify(manifest).split(FLAG).length - 1
		expect(hits).toBeGreaterThan(8)
	})
})
