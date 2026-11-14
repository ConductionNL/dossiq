/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type pages' disclosure lines sit on the normal line height, 17px
 * as boards DqZaaktypeRegels ("Een regel proberen op een zaak") and
 * DqZaaktypeStatussen ("Alle statussen, resultaten en kenmerken") draw them,
 * not on the body's 21px.
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

/**
 * The declarations of one rule in a component's style.
 *
 * @param {string} file The component, from src/.
 * @param {string} selector The selector.
 * @return {string} The declarations.
 */
function rule(file, selector) {
	const sfc = readFileSync(new URL('../../src/' + file, import.meta.url), 'utf8')
	const at = sfc.indexOf(selector + ' {', sfc.indexOf('<style'))
	return at < 0 ? '' : sfc.slice(at, sfc.indexOf('}', at))
}

describe('the case type disclosures', () => {
	it('set the rule trial line on the normal line height', () => {
		expect(rule('views/settings/tabs/RulesTab.vue', '.rules-tab__trial > summary')).toContain('line-height: normal;')
	})

	it('set the blueprint\'s "every status" line on the normal line height', () => {
		expect(rule('components/caseType/CaseTypeBlueprintWidget.vue', '.case-type-blueprint__all > summary')).toContain('line-height: normal;')
	})
})
