/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lenses, the deadline column and the bulk actions on the two index
 * pages, as the manifest declares them.
 *
 * Three of these assertions exist because the failure they catch is silent.
 *
 * A `quickFilters` list ACTIVATES A CHIP ON MOUNT — the one marked
 * `default`, and the FIRST one when none is marked. So a page that ships a
 * Mine chip without an All chip marked `default` narrows every reader's
 * first paint to their own rows, and an empty result reads as an empty
 * register rather than as a filter. The default assertion is the guard.
 *
 * The Unclaimed chip and the Queue page's base filter are the same two
 * conditions written twice. Nothing at runtime compares them, so they drift
 * the moment one side is edited; the deep-equal here is what makes "the two
 * lists agree" a fact rather than an intention.
 *
 * An operator filter is spelled `deadline[lt]`, a FLAT key. The nested
 * `{ deadline: { lt: '@today' } }` form is what a reader reaches for, and
 * `buildQueryString` JSON-stringifies a nested object value — the API then
 * receives the literal string `{"lt":"@today"}` and matches nothing, with no
 * error anywhere. The shape assertion is the only place that shows.
 *
 * @spec openspec/changes/one-case-list/specs/my-work/spec.md
 * @spec openspec/changes/one-case-list/specs/task-management/spec.md
 * @spec openspec/changes/one-case-list/specs/case-management/spec.md
 * @spec openspec/changes/one-case-list/specs/signalering-widgets/spec.md
 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const MANIFEST_PATH = path.join(ROOT, 'src', 'manifest.json')

const manifest = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))

/**
 * One page as the manifest declares it.
 *
 * @param {string} id The manifest page id.
 * @return {object} The page entry.
 */
const page = (id) => manifest.pages.find((entry) => entry.id === id)

/**
 * The quick-filter chips of one index page.
 *
 * @param {string} id The manifest page id.
 * @return {Array<object>} The chips, in declaration order.
 */
const chips = (id) => page(id).config.quickFilters

/**
 * One chip by its label.
 *
 * @param {string} id The manifest page id.
 * @param {string} label The chip label.
 * @return {object|undefined} The chip entry.
 */
const chip = (id, label) => chips(id).find((entry) => entry.label === label)

describe('Cases index lenses', () => {
	it('declares the five chips in order', () => {
		expect(chips('Cases').map((entry) => entry.label)).toEqual([
			'All',
			'Mine',
			'Unclaimed',
			'Closed',
			'Overdue',
		])
	})

	it('marks All as the default chip and nothing else', () => {
		const defaults = chips('Cases').filter((entry) => entry.default === true)
		expect(defaults).toHaveLength(1)
		expect(defaults[0].label).toBe('All')
		expect(defaults[0].filter).toEqual({})
	})

	it('keeps closed cases out of Mine and Unclaimed', () => {
		expect(chip('Cases', 'Mine').filter.isFinalStatus).toBe(false)
		expect(chip('Cases', 'Unclaimed').filter.isFinalStatus).toBe(false)
	})

	it('scopes Mine to the signed-in user through @me', () => {
		expect(chip('Cases', 'Mine').filter.assignee).toBe('@me')
	})

	it('shows closed cases under Closed only', () => {
		expect(chip('Cases', 'Closed').filter).toEqual({ isFinalStatus: true })
	})

	it('gives Unclaimed the same filter as the Queue page', () => {
		expect(chip('Cases', 'Unclaimed').filter).toEqual(
			page('Queue').config.filter,
		)
	})

	it('spells the Overdue operator as a flat bracket key', () => {
		expect(chip('Cases', 'Overdue').filter).toEqual({
			isFinalStatus: false,
			'deadline[lt]': '@today',
		})
	})
})

describe('Tasks index lenses', () => {
	it('declares the same first three labels as Cases', () => {
		expect(chips('Tasks').map((entry) => entry.label)).toEqual([
			'All',
			'Mine',
			'Unclaimed',
		])
		expect(chips('Tasks').map((entry) => entry.label)).toEqual(
			chips('Cases')
				.slice(0, 3)
				.map((entry) => entry.label),
		)
	})

	it('marks All as the default chip', () => {
		const defaults = chips('Tasks').filter((entry) => entry.default === true)
		expect(defaults).toHaveLength(1)
		expect(defaults[0].label).toBe('All')
	})

	it('keeps completed tasks out of Mine and Unclaimed', () => {
		expect(chip('Tasks', 'Mine').filter).toEqual({
			assignee: '@me',
			isTerminalStatus: false,
		})
		expect(chip('Tasks', 'Unclaimed').filter).toEqual({
			assignee: 'IS NULL',
			isTerminalStatus: false,
		})
	})
})

describe('what this change does NOT move', () => {
	it('leaves the Queue page and the My Work page in place', () => {
		expect(page('Queue')).toBeTruthy()
		expect(page('MyWork')).toBeTruthy()
	})

	it('changes no menu entry', () => {
		expect(manifest.menu.map((entry) => entry.label)).toEqual([
			'Dashboard',
			'Queue',
			'Assigned to me',
			'My work',
			'All cases',
			'Tasks',
			'Workflow board',
			'Reports',
			'Processing time',
			'Process mining',
			'Map',
			'Settings',
			'Documentation',
			'Store',
			'Organisations',
			'Map layers',
			'Case types',
			'Flows',
			'Objection advisory committees',
			'Deadline monitoring',
			'Substitutions & reassignment',
			'Features & roadmap',
			'Processing activities (AVG)',
			'AI oversight',
		])
	})
})
