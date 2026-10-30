/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqBeheerGeavanceerd "AWB-termijndefinities": the calendar line is the
 * card's second paragraph, 14px muted at 1.5 with the button 16px under it,
 * and the table ends the card on its 20px padding.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const sfc = fs.readFileSync(path.resolve(__dirname, '../../src/views/settings/tabs/DeadlineDefinitionsTab.vue'), 'utf8')
const style = sfc.slice(sfc.indexOf('<style'))

/**
 * @param {string} selector The selector.
 * @return {string} The rule's body.
 */
function rule(selector) {
	const at = style.indexOf(`${selector} {`)
	expect(at).toBeGreaterThan(-1)
	return style.slice(at, style.indexOf('}', at))
}

describe('the AWB term definitions card (DqBeheerGeavanceerd)', () => {
	it('sets the calendar line 14px muted at 1.5, 16px above the button', () => {
		const line = rule('.deadline-definitions-tab__calendar')
		expect(line).toContain('color: var(--color-text-maxcontrast);')
		expect(line).toContain('font-size: 14px;')
		expect(line).toContain('line-height: 1.5;')
		expect(line).toContain('margin: 0 0 16px;')
	})

	it('adds no padding under the table', () => {
		expect(rule('.deadline-definitions-tab')).toContain('padding: 0;')
	})
})
