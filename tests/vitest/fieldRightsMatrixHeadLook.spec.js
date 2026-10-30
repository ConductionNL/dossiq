/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The field rights matrix draws its heads as DqZaaktypeVelden does: 14px
 * text, the sort label square at the font's normal line height, the funnel
 * with a 4px radius; its rows 11px high padded, the field name plain. Read
 * from the component's style block.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = readFileSync(resolve(__dirname, '../../src/components/caseType/CaseTypeFieldRightsWidget.vue'), 'utf8')
const style = source.slice(source.indexOf('<style')).replace(/\/\*[\s\S]*?\*\//g, '')
/**
 * The body of the rule with this selector.
 *
 * @param {string} selector The selector.
 * @return {string} The body, or '' when there is none.
 */
function rule(selector) {
	const at = style.indexOf(`${selector} {`)
	return at < 0 ? '' : style.slice(at, style.indexOf('}', at))
}

describe('field rights matrix head look', () => {
	it('sets the table at 14px', () => {
		expect(rule('.case-type-field-rights__table')).toContain('font-size: 14px;')
	})

	it('draws the sort label square at normal line height', () => {
		const body = rule('.case-type-field-rights__sort')
		expect(body).toContain('border-radius: 0;')
		expect(body).toContain('line-height: normal;')
	})

	it('rounds the funnel 4px', () => {
		expect(rule('.case-type-field-rights__filter :deep(.button-vue)')).toContain('border-radius: 4px;')
	})

	it('grounds the head as the boards\' near-white table head', () => {
		expect(rule('.case-type-field-rights__table thead th')).toContain('background: var(--cn-board-table-head-bg')
	})

	it('sets heads and cells at the normal line height, centred in their row, as the board does', () => {
		const body = rule('.case-type-field-rights__table th,\n.case-type-field-rights__table td')
		expect(body).toContain('line-height: normal;')
		expect(body).toContain('vertical-align: middle;')
	})

	it('pads a body row 11px and draws the field name as plain text on the card', () => {
		expect(rule('.case-type-field-rights__table tbody th,\n.case-type-field-rights__table tbody td')).toContain('padding: 11px 12px;')
		const field = rule('.case-type-field-rights__field')
		expect(field).toContain('font-weight: 400;')
		expect(field).toContain('background: transparent;')
	})

	it('fits the card: a fixed layout and heads that wrap beside a funnel of fixed size', () => {
		expect(rule('.case-type-field-rights__table')).toContain('table-layout: fixed;')
		expect(rule('.case-type-field-rights__table thead th')).toContain('white-space: normal;')
		expect(rule('.case-type-field-rights__filter')).toContain('flex: none;')
	})
})

