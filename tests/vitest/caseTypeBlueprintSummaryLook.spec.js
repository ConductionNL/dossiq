/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The blueprint card's facts as DqZaaktypeStatussen draws them: a label
 * column as wide as its longest label, left aligned, 8px 16px apart, values
 * at 500, straight on the card's own inset. jsdom draws no layout, so the
 * rules are read from the component's style block.
 *
 * @spec openspec/specs/case-types/spec.md
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const sfc = readFileSync(new URL('../../src/components/caseType/CaseTypeBlueprintWidget.vue', import.meta.url), 'utf8')
const style = sfc.slice(sfc.indexOf('<style'))

/**
 * The body of the rule for exactly this selector.
 *
 * @param {string} selector The selector.
 * @return {string} The body, or ''.
 */
function rule(selector) {
	const m = style.match(new RegExp(selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{([^}]*)\\}'))
	return m ? m[1] : ''
}

describe('the blueprint summary card against DqZaaktypeStatussen', () => {
	it('lays the facts out as the board: label column to content, left aligned, values at 500', () => {
		expect(rule('.case-type-blueprint__summary')).toContain('grid-template-columns: max-content 1fr;')
		expect(rule('.case-type-blueprint__summary')).toContain('gap: 8px 16px;')
		expect(rule('.case-type-blueprint__fact dt')).toContain('text-align: start;')
		expect(rule('.case-type-blueprint__fact dd')).toContain('font-weight: 500;')
	})

	it('adds no inset of its own inside the card', () => {
		expect(rule('.case-type-blueprint')).toContain('padding: 0;')
	})
})

describe('the blueprint facts on their own leading', () => {
	it('drops the 12px Nextcloud pads every dt and dd, so the facts sit 8px apart', () => {
		const body = (style.match(/\.case-type-blueprint__fact dt,\s*\.case-type-blueprint__fact dd\s*\{([^}]*)\}/) || [])[1] || ''
		expect(body).toContain('padding: 0;')
		expect(body).toContain('line-height: normal;')
	})
})
