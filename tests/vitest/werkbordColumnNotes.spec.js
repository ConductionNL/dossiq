/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqWerkbord says under a column heading why it behaves as it does: a
 * full column refuses a card, a merged one has no limit.
 *
 * @spec openspec/specs/case-page/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const board = readJson('src', 'manifest.json').pages.find((page) => page.id === 'Cases').config.board
const nl = readJson('l10n', 'nl.json').translations

describe('the column notes of DqWerkbord', () => {
	it('words a full column and a merged column in the board\'s Dutch', () => {
		expect(nl[board.fullNote]).toBe('Vol: een kaart hierheen slepen wordt geweigerd')
		expect(nl[board.mergedNote]).toBe('Samengevoegd uit {count} zaaktypen: geen limiet')
	})
})
