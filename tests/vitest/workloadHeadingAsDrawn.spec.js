/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The team workload heading on Aan mij toegewezen sits as board
 * DqAanMijToegewezen draws it: an 18px title over a 14px hint, both on the
 * normal line height, with only the page's 20px gap below it.
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const sfc = readFileSync(new URL('../../src/views/WorkloadSummaryBar.vue', import.meta.url), 'utf8')
const style = sfc.slice(sfc.indexOf('<style'))

/**
 * The declarations of the heading variant's block, nested rules included.
 *
 * @return {string} The block.
 */
function headingBlock() {
	const at = style.indexOf('.workload-summary--heading {')
	return at < 0 ? '' : style.slice(at, style.indexOf('\n}', at))
}

describe('the workload heading', () => {
	it('leaves the space below it to the page gap', () => {
		expect(headingBlock()).toMatch(/^\.workload-summary--heading \{\n\tmargin-bottom: 0;/)
	})

	it('sets the title and the hint on the normal line height', () => {
		expect(headingBlock().match(/line-height: normal;/g)).toHaveLength(2)
	})
})
