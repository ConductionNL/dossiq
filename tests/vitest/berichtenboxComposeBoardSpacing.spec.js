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
		expect(rule('.compose-dialog :deep(.input-field)')).toContain('margin-block: -2px;')
		expect(rule('.compose-dialog textarea.compose-dialog__body')).toContain('margin: 0;')
		expect(rule('.compose-dialog > :deep(.notecard)')).toContain('margin: 0;')
	})

	it('gives every field and the template select the board\'s 40px in the flow, the body 8 rows at 18px (r33)', () => {
		expect(rule('.compose-dialog__field .v-select.select')).toContain('margin: -2px 0;')
		expect(style).toMatch(/\n\.compose-dialog__body \{[^}]*min-height: 0;[^}]*line-height: 1\.2;/)
		const template = readFileSync(new URL('../../src/components/case/MessageTemplateSelect.vue', import.meta.url), 'utf8')
		expect(template).toMatch(/\.message-template-select__label \{[^}]*line-height: normal;/)
		expect(template).toMatch(/\.message-template-select \.v-select\.select \{[^}]*margin: -2px 0;/)
	})
})
