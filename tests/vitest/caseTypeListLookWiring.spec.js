/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Cases list narrowed to one case type takes that case type's own look
 * (`x-index`: title, count line, add label, columns, ...). The library applies
 * it from a `folderSidebar` scope whose rows are the case types, so this pins
 * the two declarations that have to meet for it to happen and that fail
 * silently when they drift apart: the Cases page's folder scope, and every
 * column a seeded case type names being one the page declares (a column the
 * page does not declare is dropped from the scope without a word).
 *
 * @spec openspec/changes/screens-round12-case-type-lists/specs/case-type-list-layout/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const casesPage = manifest.pages.find((page) => page.id === 'Cases')

/**
 * Every seeded case type that declares a list look, by slug.
 *
 * @return {Array<{slug: string, xIndex: object}>} The case types.
 */
function caseTypesWithALook() {
	const files = [
		path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'),
		...fs.readdirSync(path.join(ROOT, 'lib', 'Settings', 'register.d'))
			.filter((name) => name.endsWith('.json'))
			.map((name) => path.join(ROOT, 'lib', 'Settings', 'register.d', name)),
	]
	const found = []
	for (const file of files) {
		const objects = JSON.parse(fs.readFileSync(file, 'utf8'))?.components?.objects
		for (const object of Array.isArray(objects) ? objects : []) {
			const xIndex = object['x-index']
			if (xIndex && typeof xIndex === 'object' && typeof xIndex.title === 'string') {
				found.push({ slug: object['@self']?.slug ?? path.basename(file), xIndex })
			}
		}
	}
	return found
}

/**
 * A page column's key, whether declared as a string or an object.
 *
 * @param {string|object} column The column.
 * @return {string} The key.
 */
const keyOf = (column) => (typeof column === 'string' ? column : column.key)

describe('the Cases list takes the look of the case type it is narrowed to', () => {
	it('scopes the list by case type through a register folder scope', () => {
		expect(casesPage.config.folderSidebar).toMatchObject({
			source: 'register',
			register: 'dossiq',
			schema: 'caseType',
			filterField: 'caseType',
		})
	})

	it('has seeded case types that carry their own heading', () => {
		const titles = caseTypesWithALook().map((caseType) => caseType.xIndex.title)
		expect(titles).toContain('Complaints')
		expect(titles).toContain('Woo requests')
	})

	it('names only columns the Cases page declares, so none is dropped', () => {
		const declared = new Set(casesPage.config.columns.map(keyOf))
		for (const { slug, xIndex } of caseTypesWithALook()) {
			const columns = (xIndex.columns ?? []).map(keyOf)
			expect(columns.filter((key) => !declared.has(key)), slug).toEqual([])
		}
	})
})
