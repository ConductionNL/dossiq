/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqBesluit (r34 letter card y +48, notes y +56): the decide dialog's labels
 * and help lines sit on the font's normal line, the date field and the text
 * area leave no descender gap, and the text area takes no margin or ring
 * room above or below, so every field stands as the board draws it.
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const sfc = fs.readFileSync(path.resolve(__dirname, '../../src/dialogs/AssessmentDecisionDialog.vue'), 'utf8')

function block(selector) {
	const at = sfc.indexOf(selector)
	expect(at).toBeGreaterThan(-1)
	return sfc.slice(at, sfc.indexOf('}', at))
}

describe('the decide dialog field rhythm', () => {
	it('puts labels and help lines on the normal line', () => {
		expect(block('&__label {')).toContain('line-height: normal')
		expect(block('&__hint {')).toContain('line-height: normal')
	})

	it('leaves no descender gap under the date field', () => {
		expect(block('&__date {')).toContain('vertical-align: top')
	})

	it('takes the text area\'s margin and ring room out', () => {
		const body = block('& :deep(.textarea) {')
		expect(body).toContain('margin-block: -2px')
		expect(block('& :deep(.textarea__input) {')).toContain('vertical-align: top')
	})
})
