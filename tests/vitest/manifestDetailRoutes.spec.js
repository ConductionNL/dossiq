// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A `type: detail` page must route to an object.
 *
 * The defect this pins down: `TaskNew` was a detail page at `/tasks/new`.
 * CnDetailPage takes the object id from the route, fetched the object
 * "new", got nothing and rendered an empty page, and the tasks tab's "New
 * task" sent people there. A detail page whose route has no parameter can
 * only ever do that, so the rule is mechanical: every detail route carries
 * one. Creation is the index page's job (`?action=create` opens its dialog).
 *
 * @spec openspec/specs/task-management/spec.md
 */
import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

describe('manifest detail pages', () => {
	const detailPages = manifest.pages.filter((p) => p.type === 'detail')

	it('has detail pages to check', () => {
		expect(detailPages.length).toBeGreaterThan(0)
	})

	it('every detail page route carries an object parameter', () => {
		const withoutParam = detailPages
			.filter((p) => !/:[A-Za-z_]+/.test(p.route))
			.map((p) => `${p.id} (${p.route})`)
		expect(withoutParam).toEqual([])
	})

	it('no page claims /tasks/new', () => {
		expect(manifest.pages.find((p) => p.route === '/tasks/new')).toBeUndefined()
		expect(manifest.pages.find((p) => p.id === 'TaskNew')).toBeUndefined()
	})

	it('the Tasks index resolves the case reference it shows', () => {
		const tasks = manifest.pages.find((p) => p.id === 'Tasks')
		expect(tasks.config.extend).toContain('case')
		const caseColumn = tasks.config.columns.find(
			(c) => typeof c === 'object' && c.key === 'case.title',
		)
		expect(caseColumn, 'a column reading the expanded case title').toBeTruthy()
		expect(tasks.config.columns).not.toContain('case')
	})
})
