/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqWerkbord: over the board the bulk band reads "1 zaak geselecteerd ·
 * Status wijzigen · Annuleren", only while cards are selected.
 *
 * @spec openspec/specs/case-bulk-status-transition/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const cases = readJson('src', 'manifest.json').pages.find((page) => page.id === 'Cases').config
const nl = readJson('l10n', 'nl.json').translations

describe('the bulk band over the case board (DqWerkbord)', () => {
	const board = cases.bulkBandViews.board

	it('counts the selected cards in Dutch', () => {
		expect(nl[board.lead.one].replace('{count}', '1')).toBe('1 zaak geselecteerd')
		expect(nl[board.lead.other].replace('{count}', '3')).toBe('3 zaken geselecteerd')
	})

	it('offers the status move as "Status wijzigen" and a cancel', () => {
		expect(board.actions).toEqual([{ id: 'transition', label: 'Change status' }])
		expect(cases.bulkActions.some((action) => action.id === 'transition')).toBe(true)
		expect(nl['Change status']).toBe('Status wijzigen')
		expect(board.cancel).toBe(true)
	})
})
