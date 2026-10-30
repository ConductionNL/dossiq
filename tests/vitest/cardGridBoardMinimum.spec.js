// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * DqZakenSorteren: three case cards beside the open side panel, four at full
 * width. Dossiq sets the library's card track minimum for its lists in the
 * board look only; the fleet default stays.
 *
 * @spec openspec/specs/case-search-and-lists/spec.md#requirement-the-case-cards-fit-three-beside-the-side-panel
 */
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const css = readFileSync(path.resolve(__dirname, '../../src/assets/app.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')

describe('the case card track in the board look', () => {
	it('takes a 232px minimum on dossiq index pages only', () => {
		const rule = css.match(/\.cn-look-board \.cn-index-page,\s*\.cn-index-page\.cn-look-board \{([^}]*)\}/)
		expect(rule).not.toBeNull()
		expect(rule[1]).toContain('--cn-card-grid-min: 232px;')
	})
})
