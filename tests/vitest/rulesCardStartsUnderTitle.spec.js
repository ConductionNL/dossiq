/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A board card that opens on the rule rows starts 12px under its title, not
 * 0.15em higher as for a first line of text (r31 DqZaaktypeOverzicht Regels
 * gapBelowTitle 27.2 vs 29.5). Read from the component's style.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const SOURCE = fs.readFileSync(path.resolve(__dirname, '../../src/views/settings/tabs/RulesTab.vue'), 'utf8')

describe('the rules card on a board', () => {
	it('pads its content 12px at the top when it opens on the rule rows', () => {
		const at = SOURCE.indexOf('.cn-look-board .cn-detail-page .cn-widget-wrapper__content:has(> .rules-tab > .rules-tab__rows:first-child):not(.cn-look-nextcloud *) {')
		expect(at).toBeGreaterThan(-1)
		expect(SOURCE.slice(at, SOURCE.indexOf('}', at))).toContain('padding-top: 12px;')
	})
})
