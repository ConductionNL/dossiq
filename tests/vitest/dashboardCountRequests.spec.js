// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The addresses the simple dashboard asks for its counts.
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
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

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
).pages.find((page) => page.id === 'Dashboard')
const widget = (id) => dashboard.config.widgets.find((item) => item.id === id)

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
		for (const item of dashboard.config.widgets) {
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
