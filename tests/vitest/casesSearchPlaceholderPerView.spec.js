/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The cases list asks its search in each view's words: the list "Zoek op
 * zaak, nummer of verzoeker", the board view "Zoek op zaak of verzoeker"
 * (DqWerkbord), the map "Filter op zaak of aanvrager" (DqZakenKaart).
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import nl from '../../l10n/nl.json'
import manifest from '../../src/manifest.json'

const config = manifest.pages.find((page) => page.id === 'Cases' && page.route === '/cases').config

describe('the cases search placeholder per view', () => {
	it('names the list, board and map placeholders', () => {
		expect(config.searchPlaceholder).toBe('Search by case, number or requester')
		expect(config.searchPlaceholderByView).toEqual({ board: 'Search by case or requester', map: 'Filter by case or applicant' })
	})

	it('reads them in Dutch as the boards draw them', () => {
		expect(nl.translations[config.searchPlaceholder]).toBe('Zoek op zaak, nummer of verzoeker')
		expect(nl.translations[config.searchPlaceholderByView.board]).toBe('Zoek op zaak of verzoeker')
		expect(nl.translations[config.searchPlaceholderByView.map]).toBe('Filter op zaak of aanvrager')
	})
})
