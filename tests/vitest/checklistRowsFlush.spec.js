/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqVthInstellingen Inspectiechecklists: the rows start at the card's content
 * edge (live: 12px further in), under a line that closes the title off.
 *
 * @spec openspec/specs/vth-checklists-as-rows/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const source = fs.readFileSync(path.resolve(__dirname, '../../src/views/settings/tabs/ChecklistTemplatesTab.vue'), 'utf8')
/**
 * @param {string} selector The rule's selector.
 * @return {string} Its body, or ''.
 */
function body(selector) {
	const at = source.indexOf(`${selector} {`)
	return at === -1 ? '' : source.slice(at, source.indexOf('}', at))
}

describe('the checklist rows', () => {
	it('start at the card\'s content edge', () => {
		expect(body('.checklists-tab__item.checklists-tab__item')).toContain('padding-inline: 0;')
	})

	it('sit under a line', () => {
		expect(body('.checklists-tab__list')).toContain('border-top: 1px solid var(--color-border);')
	})
})
