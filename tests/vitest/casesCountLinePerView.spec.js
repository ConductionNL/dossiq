/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The cases list says per view what it shows under its title: the table
 * "8 van 48 lopende zaken in uw teams · gesorteerd op termijn" (DqZaken), the
 * board view "· bordweergave per status" (DqWerkbord), the map only the count,
 * before the library adds "· 5 met een adres op de kaart" (DqZakenKaart).
 * Needs nextcloud-vue `countTextByView` (count-text-per-view).
 *
 * @spec openspec/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import nl from '../../l10n/nl.json'

const ROOT = path.resolve(__dirname, '../..')
const config = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8')).pages.find((page) => page.id === 'Cases').config

describe('the cases count line per view', () => {
	it('keeps the sort in the table and names the board and the map lines', () => {
		expect(config.countText).toBe('{shown} of {total} open cases in your teams, sorted by deadline')
		expect(config.countTextByView).toEqual({
			board: '{shown} of {total} open cases in your teams, board view by status',
			map: '{shown} of {total} open cases in your teams',
		})
	})

	it('reads them in Dutch as the boards draw them', () => {
		expect(nl.translations[config.countText]).toBe('{shown} van {total} lopende zaken in uw teams · gesorteerd op termijn')
		expect(nl.translations[config.countTextByView.board]).toBe('{shown} van {total} lopende zaken in uw teams · bordweergave per status')
		expect(nl.translations[config.countTextByView.map]).toBe('{shown} van {total} lopende zaken in uw teams')
	})

	it('names an applied saved view\'s conditions, as DqFilterInMenu draws them (nextcloud-vue savedViewCountText)', () => {
		expect(config.savedViewCountText).toBe('{shown} of {total} open cases · {view} · sorted by deadline')
		expect(nl.translations[config.savedViewCountText]).toBe('{shown} van {total} lopende zaken · {view} · gesorteerd op termijn')
	})

	it('ends the footer with the page size, as every case list board draws it ("klik een kolomkop om te sorteren · 20 per pagina")', () => {
		expect(config.footerNote).toBe('click a column header to sort · 20 per page')
		expect(nl.translations[config.footerNote]).toBe('klik een kolomkop om te sorteren · 20 per pagina')
	})
})
