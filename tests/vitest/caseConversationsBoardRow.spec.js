/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The live conversation list in the board look (DqZaakContact "Live gesprek"):
 * one 56px row per conversation, the subject and meta line on the normal line
 * beside the badge, the footnote 12px under the list. Read from the panel's
 * style block, so Nextcloud's own look keeps the stacked rows.
 *
 * @spec openspec/changes/board-live-conversation-row/specs/board-live-conversation-row/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const source = fs.readFileSync(path.resolve(__dirname, '../../src/views/cases/components/CaseConversationsPanel.vue'), 'utf8')
const style = source.slice(source.indexOf('<style')).replace(/\/\*[\s\S]*?\*\//g, '')

/**
 * The body of the rule with exactly this selector, '' when absent.
 *
 * @param {string} selector The selector.
 * @return {string} The body.
 */
function rule(selector) {
	const at = style.indexOf('\n' + selector + ' {')
	return at === -1 ? '' : style.slice(at, style.indexOf('}', at))
}

describe('CaseConversationsPanel in the board look', () => {
	it('lays a conversation out as one row, 10px above and below, the badge beside the text', () => {
		const row = rule('.cn-look-board .case-conversations__record')
		expect(row).toContain('display: grid;')
		expect(row).toContain('padding-block: 10px;')
		expect(rule('.cn-look-board .case-conversations__head')).toContain('display: contents;')
		expect(rule('.cn-look-board .case-conversations__head > .cn-status-badge')).toContain('grid-area: 1 / 2 / 3 / 3;')
	})

	it('sets the subject and the meta line on the normal line, the meta at 13px', () => {
		expect(rule('.cn-look-board .case-conversations__subject')).toContain('line-height: normal;')
		const meta = rule('.cn-look-board .case-conversations__meta')
		expect(meta).toContain('font-size: 13px;')
		expect(meta).toContain('line-height: normal;')
	})

	it('keeps the footnote 12px under the list', () => {
		expect(rule('.cn-look-board .case-conversations__footnote')).toContain('margin-top: 12px;')
	})

	it('leaves the stacked row of the default look alone', () => {
		expect(rule('.case-conversations__record')).toContain('flex-direction: column;')
	})
})
