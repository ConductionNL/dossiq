/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqBesluit (r32 verdicts): the letter card is 10px 14px in with 8px corners,
 * its mark a 32px tile on the success tint, its title on the normal line; a
 * ground chip is 4px 10px on the normal line (23px).
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const sfc = fs.readFileSync(path.resolve(__dirname, '../../src/dialogs/AssessmentDecisionDialog.vue'), 'utf8')

/**
 * The body of a nested SCSS block by its `&__name`.
 *
 * @param {string} name The block, such as `__letter`.
 * @return {string} The block body, or ''.
 */
function block(name) {
	const at = sfc.indexOf(`&${name} {`)
	return at === -1 ? '' : sfc.slice(at, sfc.indexOf('}', at))
}

describe('the decide dialog letter as DqBesluit draws it', () => {
	it('draws the card 10px 14px in with 8px corners', () => {
		expect(block('__letter')).toContain('padding: 10px 14px')
		expect(block('__letter')).toContain('border-radius: 8px')
	})

	it('sets the mark on a 32px success tile and the text on the normal line', () => {
		expect(sfc).toContain('<span class="assessment-decision__letter-icon"><FileDocumentCheckOutline :size="18" /></span>')
		expect(block('__letter-icon')).toContain('width: 32px')
		expect(block('__letter-text')).toContain('line-height: normal')
		expect(sfc.slice(sfc.indexOf('&__letter-text {'), sfc.indexOf('&__chips {'))).toContain('font-size: 15px')
	})

	it('draws a ground chip 4px 10px on the normal line', () => {
		expect(block('__chip')).toContain('padding: 4px 10px')
		expect(block('__chip')).toContain('line-height: normal')
	})
})
