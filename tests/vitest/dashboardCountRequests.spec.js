// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The addresses the simple landing page asks for its counts (its My work
 * view since landing-views; the simple dashboard asked them before).
 *
 * `simpleListAndDashboard.spec.js` compares FILTERS. That was not enough: the
 * First today card declared `deadline: { lt: ... }`, the filter read correct,
 * and the request that left the browser carried the operator as JSON, which
 * OpenRegister answers with a 500 (live check, 5 October 2026). So this spec
 * builds each request the way the library builds it and reads the address.
 *
 * The card goes through the library's own `resolveFilterTokens` and
 * `buildQueryString`, the two calls `utils/visibleWhen.js` makes. The tiles
 * and the week strip flatten their filter inside a component, in a few lines
 * that are repeated here; if the library changes those lines this copy goes
 * stale, and the note says so rather than pretending otherwise.
 *
 * @spec openspec/changes/simple-list-and-dashboard/specs/dashboard/spec.md#REQ-DASH-025
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { buildQueryString } from '@conduction/nextcloud-vue/src/utils/headers.js'
import { resolveFilterTokens } from '@conduction/nextcloud-vue/src/utils/resolveFilterTokens.js'
import {
	compareVisibleWhen,
	readVisibleWhenValue,
} from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import fs from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'
import { pageWidgets } from './helpers/pageViews.js'

const ROOT = path.resolve(__dirname, '../..')
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}
const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const dashboard = buildProfiledManifest(
	buildManifest,
	readJson('src', 'manifest.json'),
	fragments,
	readJson('src', 'menu-layout.simple.json'),
).pages.find((page) => page.id === 'MyWorkHome')
const widget = (id) => pageWidgets(dashboard).find((item) => item.id === id)

const DAY = /^\d{4}-\d{2}-\d{2}/

/**
 * The query a tile or the week strip sends, by the library's flattening:
 * a nested operator becomes `<prefix>field[op]`.
 *
 * @param {object} filter The widget's filter.
 * @param {(field: string) => string} name How the widget spells a field.
 * @return {URLSearchParams} The parameters.
 */
function flattened(filter, name) {
	const params = new URLSearchParams()
	for (const [field, value] of Object.entries(resolveFilterTokens(filter, {}))) {
		if (value && typeof value === 'object') {
			for (const [op, operand] of Object.entries(value)) {
				params.set(`${name(field)}[${op}]`, String(operand))
			}
		} else {
			params.set(name(field), String(value))
		}
	}
	return params
}

describe('what the simple dashboard asks OpenRegister', () => {
	it('asks the First today count with a bracket operator, never with JSON', () => {
		const source = widget('simple-first-today').content.visibleWhen.source
		const query = buildQueryString({
			...resolveFilterTokens(source.filter, {}),
			_limit: 1,
		})
		const params = new URLSearchParams(query)

		expect(params.get('deadline[lt]')).toMatch(DAY)
		expect(
			params.has('deadline'),
			'the operator went out as one JSON value',
		).toBe(false)
		expect(decodeURIComponent(query)).not.toContain('{')
		expect(params.get('isFinalStatus')).toBe('false')
		expect(params.get('_limit')).toBe('1')
		// No token left unresolved in the address.
		expect(decodeURIComponent(query)).not.toContain('@')
	})

	it('holds no nested operator anywhere the library would write it as JSON', () => {
		// Every `visibleWhen.source.filter` on the page goes through
		// buildQueryString, so none may hold an object value.
		for (const item of pageWidgets(dashboard)) {
			const filter =
				item.visibleWhen?.source?.filter
				?? item.content?.visibleWhen?.source?.filter
			for (const [field, value] of Object.entries(filter ?? {})) {
				expect(typeof value, `${item.id}: ${field}`).not.toBe('object')
			}
		}
	})

	it('asks the four tiles with filter[field][op], dates resolved', () => {
		const due = flattened(
			widget('simple-due-soon').content.source.filter,
			(field) => `filter[${field}]`,
		)
		expect(due.get('filter[deadline][gte]')).toMatch(DAY)
		expect(due.get('filter[deadline][lt]')).toMatch(DAY)
		// Seven days: today up to, and not including, the same weekday next week.
		const span =
			(new Date(due.get('filter[deadline][lt]'))
				- new Date(due.get('filter[deadline][gte]')))
			/ 86400000
		expect(span).toBe(7)

		const closed = flattened(
			widget('simple-closed-month').content.source.filter,
			(field) => `filter[${field}]`,
		)
		expect(closed.get('filter[endDate][gte]')).toMatch(DAY)
		expect(closed.get('filter[isFinalStatus]')).toBe('true')

		for (const id of [
			'simple-my-open',
			'simple-due-soon',
			'simple-waiting',
			'simple-closed-month',
		]) {
			const params = flattened(
				widget(id).content.source.filter,
				(f) => `filter[${f}]`,
			)
			expect([...params.values()].join(' '), id).not.toMatch(/[{@]/)
		}
	})

	it('asks the week strip with plain fields and no operator of its own', () => {
		const params = flattened(
			widget('simple-week').content.source.filter,
			(f) => f,
		)
		expect([...params.keys()].sort()).toEqual([
			'assignee',
			'isDraft',
			'isFinalStatus',
			'statusHiddenInLists',
		])
	})
})

describe('whether the First today card shows', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	/**
	 * Evaluate the card's condition the way CnDashboardPage does: the
	 * library's `readVisibleWhenValue` and `compareVisibleWhen`, against
	 * OpenRegister's real list answer for a `_limit=1` read.
	 *
	 * @param {number} total The total OpenRegister reports.
	 * @return {Promise<{met: boolean, value: unknown, url: string}>} The verdict, the value and the address asked.
	 */
	async function verdict(total) {
		const asked = []
		vi.stubGlobal('fetch', async (url) => {
			asked.push(String(url))
			return {
				ok: true,
				status: 200,
				json: async () => ({
					results: total > 0 ? [{ id: 'one' }] : [],
					total,
					page: 1,
					pages: total,
					limit: 1,
				}),
			}
		})
		const cond = widget('simple-first-today').content.visibleWhen
		const value = await readVisibleWhenValue(cond)
		return {
			met: compareVisibleWhen(value, cond.op || 'eq', cond.value),
			value,
			url: asked[0],
		}
	}

	it('is met on the total, not on the one row a _limit=1 read returns', async () => {
		const many = await verdict(58)
		expect(many.value).toBe(58)
		expect(many.met).toBe(true)
		expect(decodeURIComponent(many.url)).toContain('deadline[lt]=')
		expect(decodeURIComponent(many.url)).toContain('_limit=1')

		const none = await verdict(0)
		expect(none.value).toBe(0)
		expect(none.met).toBe(false)
	})

	it('carries a text, because the dashboard gives up the cell of a banner without one', () => {
		// CnDashboardPage.isCollapsedWidget: `isBannerDef(def) && text === ''`
		// collapses the cell BEFORE the condition is looked at, and `text` is
		// `content.text`, never `content.title`. A met condition is not enough.
		const card = widget('simple-first-today')
		expect(card.type).toBe('banner')
		expect(card.content.text).toBeTruthy()
		expect(card.content.text).toBe(card.content.title)
		// The count the card names is the value the condition read.
		expect(card.content.reason).toContain('{value}')
		// And the page reads the condition from where it is declared.
		expect(card.visibleWhen ?? card.content.visibleWhen).toBeTruthy()
	})
})
