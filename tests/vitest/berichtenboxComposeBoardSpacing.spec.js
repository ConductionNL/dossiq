/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Bericht naar de Berichtenbox spaced as DqBerichtVersturen draws it: labels
 * and notes on their natural leading, the decision box a plain line with its
 * note 4px under it, and no input, textarea or note-card margins on top of the
 * form's 18px gap.
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const sfc = readFileSync(new URL('../../src/dialogs/BerichtenboxComposeDialog.vue', import.meta.url), 'utf8')
const style = sfc.slice(sfc.indexOf('<style'))
const rule = (selector) => {
	const m = style.match(new RegExp(selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{([^}]*)\\}'))
	return m ? m[1] : ''
}

describe('the Berichtenbox dialog against DqBerichtVersturen', () => {
	it('sets labels and notes on their natural leading', () => {
		expect(rule('.compose-dialog__label')).toContain('line-height: normal;')
		expect(rule('.compose-dialog__hint')).toContain('line-height: normal;')
	})

	it('draws the decision box as a plain line, the note 4px under it', () => {
		expect(rule('.compose-dialog__check')).toContain('gap: 4px;')
		expect(rule('.compose-dialog__check :deep(.checkbox-content)')).toContain('padding-block: 0;')
		expect(style).toMatch(/:deep\(\.checkbox-radio-switch__text\)\s*\{[^}]*font-weight: 400;/)
		expect(style).toMatch(/\.compose-dialog__check :deep\(\.checkbox-radio-switch\),/)
		expect(style).not.toContain(':deep(.input-field__main-wrapper)')
	})

	it('takes the input, textarea and note-card margins off', () => {
		expect(rule('.compose-dialog :deep(.input-field)')).toContain('margin-block: 0;')
		expect(rule('.compose-dialog textarea.compose-dialog__body')).toContain('margin: 0;')
		expect(rule('.compose-dialog > :deep(.notecard)')).toContain('margin: 0;')
	})
})
