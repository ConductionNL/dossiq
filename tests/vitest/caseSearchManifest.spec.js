// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the Cases page declares about search.
 *
 * Two declarations, both of which read fine in a diff and do nothing if they
 * are wrong, which is why the manifest is read from disk here rather than
 * described.
 *
 * 🔴 THE LENS IS SPELLED IN OPENREGISTER'S GRAMMAR, NOT IN DOSSIQ'S.
 * `result_isnull=true` is the suffix form of `MagicSearchHandler`'s `isnull`
 * operator. A suffix outside `COMPARISON_OPERATORS` contributes no condition
 * and is silently ignored, so a misspelling here narrows nothing and the lens
 * shows every closed case as though none of them recorded a result.
 *
 * 🔴 THE SLOT NAME IS THE LIBRARY'S. `CnPageRenderer` resolves `pages[].slots`
 * into named slots on the page component and drops any entry whose registry
 * name does not resolve, without a warning. A typo in either half renders
 * nothing, which looks exactly like a page where no search was ever refused.
 *
 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registry = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

const casesPage = manifest.pages.find((page) => page.id === 'Cases')

describe('the Cases page and a refused search', () => {
	it('mounts the refusal above the list, where the search box is', () => {
		expect(casesPage.slots).toEqual({ 'below-header': 'CaseSearchRefusal' })
	})

	it('names a component the registry actually resolves', () => {
		expect(registry).toContain('CaseSearchRefusal: {')
		expect(registry).toContain("from './components/search/CaseSearchRefusal.vue'")
	})
})

describe('the Cases page and the closed cases with nothing recorded', () => {
	const lens = casesPage.config.quickFilters.find(
		(filter) => filter.label === 'Closed with no result',
	)

	it('offers the lens', () => {
		expect(lens).toBeDefined()
	})

	it('asks openregister for the missing value rather than filtering a page', () => {
		expect(lens.filter).toEqual({ isFinalStatus: true, result_isnull: true })
	})

	it('leaves the Closed lens showing every closed case', () => {
		const closed = casesPage.config.quickFilters.find(
			(filter) => filter.label === 'Closed',
		)

		expect(closed.filter).toEqual({ isFinalStatus: true })
	})

	it('keeps exactly one default lens', () => {
		const defaults = casesPage.config.quickFilters.filter((filter) => filter.default === true)

		expect(defaults).toHaveLength(1)
		expect(defaults[0].label).toBe('All')
	})
})
