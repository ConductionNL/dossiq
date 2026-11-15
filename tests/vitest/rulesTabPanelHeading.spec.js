/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Rules tab as DqZaaktypeRegels draws it: headed "Regels op de zaak" with
 * its line, the add button under it at the start with a plus. A tab's single
 * widget heads its panel only when it carries a description.
 *
 * @spec openspec/specs/case-types/spec.md
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import nl from '../../l10n/nl.json'
import manifest from '../../src/manifest.json'

const page = manifest.pages.find((p) => p.id === 'CaseTypeDetail').config
const rules = page.widgets.find((w) => w.id === 'case-type-rules')
const sfc = readFileSync(new URL('../../src/views/settings/tabs/RulesTab.vue', import.meta.url), 'utf8')

describe('the Rules tab against DqZaaktypeRegels', () => {
	it('carries the description that heads its panel, in the board words', () => {
		expect(nl.translations[rules.title]).toBe('Regels op de zaak')
		expect(nl.translations[rules.description]).toBe('Alles wat het platform controleert bij het opslaan van een zaak, in de volgorde waarin het dat doet.')
	})

	it('puts Regel toevoegen at the start, with a plus', () => {
		expect(sfc).toMatch(/\.rules-tab__head \{\s*display: flex;\s*justify-content: flex-start;/)
		expect(sfc).toMatch(/data-testid="rules-add"[\s\S]{0,120}<template #icon>\s*<Plus :size="20" \/>/)
	})
})

describe('the Rules tab rows against DqZaaktypeRegels', () => {
	it('sets the add button 8px under the subtitle and 12px above the rows', () => {
		expect(sfc).toMatch(/\.rules-tab__head \{[^}]*margin: -4px 0 12px;/)
	})

	it('draws a row as the board: a 15px title at 500 and its line 2px under it, on their natural leading', () => {
		expect(sfc).toMatch(/\.rules-tab__text \{[^}]*gap: 2px;/)
		expect(sfc).toMatch(/\.rules-tab__title \{[^}]*font-size: 15px;\s*font-weight: 500;\s*line-height: normal;/)
		expect(sfc).toMatch(/\.rules-tab__line \{[^}]*line-height: normal;/)
	})
})
