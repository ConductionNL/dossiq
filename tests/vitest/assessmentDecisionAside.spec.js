/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqBesluit (r33 'dialog-card: insetTop 11>14', 59 vs 87px): the decide
 * dialog's side note is 10px 14px in, 13px on a 1.5 line in the soft ink,
 * with an 18px mark, as the board draws it.
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const sfc = fs.readFileSync(path.resolve(__dirname, '../../src/dialogs/AssessmentDecisionDialog.vue'), 'utf8')

describe('the decide dialog side note', () => {
	it('is the board\'s muted note', () => {
		const at = sfc.indexOf('&__aside {')
		const body = sfc.slice(at, sfc.indexOf('}', at))
		expect(body).toContain('padding: 10px 14px')
		expect(body).toContain('font-size: 13px')
		expect(body).toContain('line-height: 1.5')
		expect(sfc).toContain('<AccountGroupOutline :size="18" class="assessment-decision__aside-icon" />')
	})
})
