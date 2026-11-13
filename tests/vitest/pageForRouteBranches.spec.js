// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Every branch of the lookup from a route to the manifest page it renders.
 *
 * @spec openspec/specs/page-titles/spec.md
 */
import { describe, expect, it } from 'vitest'
import { pageForRoute } from '../../src/utils/pageTitle.js'

const CASES = { id: 'Cases', title: 'All cases' }
const CASE = { id: 'Case', title: 'Case' }
const MANIFEST = { pages: [null, CASES, CASE] }

describe('pageForRoute', () => {
	it('reads the page id from meta.cnPageId before the route name', () => {
		expect(pageForRoute(MANIFEST, { meta: { cnPageId: 'Case' }, name: 'Cases' })).toBe(CASE)
		expect(pageForRoute(MANIFEST, { meta: { cnPageId: '' }, name: 'Cases' })).toBe(CASES)
		expect(pageForRoute(MANIFEST, { name: 'Cases' })).toBe(CASES)
	})

	it('answers null for a route that names no page or an unknown one', () => {
		expect(pageForRoute(MANIFEST, {})).toBeNull()
		expect(pageForRoute(MANIFEST, null)).toBeNull()
		expect(pageForRoute(MANIFEST, { name: 'Nope' })).toBeNull()
	})

	it('reads a manifest without a pages list as empty', () => {
		expect(pageForRoute({ pages: {} }, { name: 'Cases' })).toBeNull()
		expect(pageForRoute(null, { name: 'Cases' })).toBeNull()
	})
})
