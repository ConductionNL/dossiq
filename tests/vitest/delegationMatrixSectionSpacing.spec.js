/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Mandaatmatrix card spaced as DqBeheerGeavanceerd draws it: 14px under
 * the section picker and 14px between the buttons and the table.
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

function styleOf(file) {
	const sfc = readFileSync(new URL(file, import.meta.url), 'utf8')
	return sfc.slice(sfc.indexOf('<style'))
}

function rule(style, selector) {
	const m = style.match(new RegExp(selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{([^}]*)\\}'))
	return m ? m[1] : ''
}

describe('the Mandaatmatrix card against DqBeheerGeavanceerd', () => {
	it('stands the section body 14px under the picker', () => {
		const style = styleOf('../../src/views/settings/tabs/DelegationMatrixTab.vue')
		expect(rule(style, '.mandaat-matrix-tab__body')).toContain('margin-top: 18px;')
	})

	it('stands the table 14px under the buttons', () => {
		const style = styleOf('../../src/views/settings/components/DelegationMatrixTable.vue')
		expect(rule(style, '.mandaat-matrix-table__toolbar')).toContain('margin-bottom: 14px;')
	})
})
