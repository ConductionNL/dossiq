/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page's Zaakgegevens as DqZaak draws them: dates as "3 okt 2026",
 * an empty result, parent case and elsewhere-handled in words, and the
 * archive status as words, not the stored code. Read from the layout.
 *
 * @spec openspec/changes/data-widget-board-dates/specs/data-widget/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '../..')
const layout = JSON.parse(fs.readFileSync(path.join(root, 'src/menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(root, 'l10n/nl.json'), 'utf8')).translations
const card = layout.pages[1].configAppend.widgets.find((widget) => widget.id === 'case-details')
const overrides = card.content.overrides

describe('the Zaakgegevens card', () => {
	it('reads its dates the board way', () => {
		for (const key of ['receivedAt', 'startDate', 'deadline']) {
			expect(overrides[key].format).toBe('date-long')
		}
	})

	it('says an empty result, parent case and elsewhere in Dutch', () => {
		expect(nl[overrides.result.emptyValue]).toBe('Nog geen resultaat')
		expect(nl[overrides.parentCase.emptyValue]).toBe('Geen')
		expect(nl[overrides.externalApplication.emptyValue]).toBe('Nee')
	})

	it('reads the archive status as words', () => {
		expect(nl[overrides.archiveStatus.enumLabels.nog_te_archiveren]).toBe('Nog te archiveren')
	})
})
