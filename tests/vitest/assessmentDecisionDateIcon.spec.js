/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqBesluit (r32 verdict "field Besluitdatum: icon"): the decide dialog's date
 * field carries the board's calendar icon at its end; the browser's picker
 * button stays under it, invisible, so a click there still opens the picker.
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const sfc = fs.readFileSync(path.resolve(__dirname, '../../src/dialogs/AssessmentDecisionDialog.vue'), 'utf8')

describe('the decide dialog date field as DqBesluit draws it', () => {
	it('carries a calendar icon at its end and keeps the browser picker under it', () => {
		expect(sfc).toContain('<CalendarBlankOutline :size="18" class="assessment-decision__date-icon" aria-hidden="true" />')
		const at = sfc.indexOf('&__date {')
		expect(sfc.slice(at, sfc.indexOf('}', at))).toContain('padding: 0 40px 0 12px')
		expect(sfc).toContain('&::-webkit-calendar-picker-indicator {')
	})
})
