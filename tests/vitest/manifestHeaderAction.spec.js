// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Finding a header action a manifest page declares, by its id.
 *
 * @spec openspec/specs/case-management/spec.md
 */
import { describe, expect, it } from 'vitest'
import { findHeaderAction } from '../../src/utils/manifestHeaderAction.js'

const manifest = {
	pages: [
		{ id: 'Dashboard', config: {} },
		{ id: 'Broken', config: { headerActions: 'not a list' } },
		null,
		{
			id: 'Cases',
			config: {
				headerActions: [
					null,
					{ id: 'new-case', label: 'New case' },
					{ id: 'export', label: 'Export' },
				],
			},
		},
		{
			id: 'Tasks',
			config: { headerActions: [{ id: 'export', label: 'Export tasks' }] },
		},
	],
}

describe('finding a header action', () => {
	it('answers the action a page declares', () => {
		expect(findHeaderAction(manifest, 'new-case')).toEqual({ id: 'new-case', label: 'New case' })
	})

	it('answers the first page\'s action when two pages declare the id', () => {
		expect(findHeaderAction(manifest, 'export').label).toBe('Export')
	})

	it('answers null for an id no page declares', () => {
		expect(findHeaderAction(manifest, 'archive')).toBeNull()
	})

	it('answers null without an id', () => {
		expect(findHeaderAction(manifest, '')).toBeNull()
		expect(findHeaderAction(manifest, undefined)).toBeNull()
	})

	it('answers null for a manifest without pages', () => {
		expect(findHeaderAction({}, 'new-case')).toBeNull()
		expect(findHeaderAction(null, 'new-case')).toBeNull()
		expect(findHeaderAction({ pages: 'none' }, 'new-case')).toBeNull()
	})
})
