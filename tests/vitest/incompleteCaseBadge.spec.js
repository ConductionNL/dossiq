// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The incompleteness travels to the list (REQ-LIFE-13), beside hold and draft.
 *
 * The point of recording incompleteness rather than refusing the intake is
 * that somebody can come back to it. A case that reads incomplete on its own
 * page and looks like every other row in the list is a case nobody comes back
 * to, so the marker is asserted in the LIST, which is where the coming back
 * starts.
 *
 * The three states are row indicators the Cases page declares: icons on the
 * title cell, each with its word as hidden text and tooltip. Three columns
 * empty on nine rows in ten would push the case title off the screen for
 * nothing, and the three states are read together anyway: what a handler wants
 * to know is whether this row needs something before it can be worked.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import { resolveRowIndicators } from '@conduction/nextcloud-vue/src/utils/rowIndicators.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const cases = manifest.pages.find((page) => page.id === 'Cases')

/**
 * A date relative to today, as the case stores it.
 *
 * @param {number} days How many days from today.
 * @return {string} The date as YYYY-MM-DD.
 */
function day(days) {
	const when = new Date()
	when.setDate(when.getDate() + days)

	return when.toISOString().slice(0, 10)
}

/**
 * The markers one row renders.
 *
 * @param {object} row The case row.
 * @return {Array<string>} The marker keys, in render order.
 */
function markersOf(row) {
	return resolveRowIndicators(cases.config.rowIndicators, row).shown.map(
		(indicator) => indicator.id,
	)
}

describe('the state markers on the case list', () => {
	it('are declared on the Cases index as row indicators, with no column of their own', () => {
		expect(cases.config.rowIndicators.map((indicator) => indicator.id)).toEqual([
			'incomplete',
			'held',
			'draft',
		])
		expect(
			cases.config.columns.find(
				(entry) => typeof entry === 'object' && entry.widget === 'caseStateMarkers',
			),
		).toBeUndefined()
	})
})

describe('an incomplete case is marked wherever it is listed', () => {
	it('marks a row whose required fields were left empty', () => {
		expect(markersOf({ isIncomplete: true })).toEqual(['incomplete'])
	})

	it('says nothing at all about a complete row', () => {
		// Four hundred rows saying Complete is noise around the three that
		// matter, and a marker on every row marks nothing.
		expect(markersOf({ isIncomplete: false })).toEqual([])
		expect(markersOf({})).toEqual([])
	})

	it('reads the flag the way every JSON boolean in this app is read', () => {
		expect(markersOf({ isIncomplete: 'true' })).toEqual(['incomplete'])
		expect(markersOf({ isIncomplete: 1 })).toEqual(['incomplete'])
		expect(markersOf({ isIncomplete: 'false' })).toEqual([])
		expect(markersOf({ isIncomplete: null })).toEqual([])
	})

	it('carries a word and not only a colour', () => {
		// WCAG 2.2 SC 1.4.1. A reader who cannot separate the hues still gets
		// the state, because every indicator carries its word as text.
		for (const indicator of cases.config.rowIndicators) {
			expect(indicator.text.trim()).not.toBe('')
			expect(indicator.icon).toBeTruthy()
		}
	})
})

describe('a held case is marked, and stops being marked on its date', () => {
	it('marks a case whose hold is still ahead', () => {
		expect(markersOf({ heldUntil: day(30) })).toEqual(['held'])
	})

	it('stops marking a case held until yesterday', () => {
		// This is what "it comes back on its date" means on the list: nothing
		// runs overnight, because a date that has passed simply stops being in
		// the future. A flag would need something to clear it, and that
		// something is exactly what would fail silently.
		expect(markersOf({ heldUntil: day(-1) })).toEqual([])
	})

	it('does not mark a row off a date nobody can parse', () => {
		// Painting a marker off an unreadable value would put a state on the
		// row that no act put there.
		expect(markersOf({ heldUntil: 'soon' })).toEqual([])
		expect(markersOf({ heldUntil: '' })).toEqual([])
	})
})

describe('the three markers together', () => {
	it('marks a draft', () => {
		expect(markersOf({ isDraft: true })).toEqual(['draft'])
	})

	it('puts incomplete first, because it is the one that changes what to do next', () => {
		const row = { isIncomplete: true, heldUntil: day(10), isDraft: true }

		expect(markersOf(row)).toEqual(['incomplete', 'held', 'draft'])
	})
})
