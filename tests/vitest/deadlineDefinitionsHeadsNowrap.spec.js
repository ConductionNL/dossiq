/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The AWB term definitions table keeps its column heads on one line, as board
 * DqBeheerGeavanceerd draws them. A head that may wrap ("Gesloten dag") asks
 * for less room under the auto table layout, so its column got 130 against
 * 140 and "Verschuift van een gesloten dag" wrapped to three lines: rows 71.4
 * against 55. Probed on :8080 with the rule injected: columns as drawn, 55.
 * Read as text.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const tab = readFileSync(resolve(__dirname, '../../src/views/settings/tabs/DeadlineDefinitionsTab.vue'), 'utf8')

describe('the AWB term definitions heads', () => {
	it('stay on one line', () => {
		const at = tab.indexOf('.deadline-definitions-tab__table th {')
		expect(at).toBeGreaterThan(-1)
		expect(tab.slice(at, tab.indexOf('}', at))).toContain('white-space: nowrap')
	})
})
