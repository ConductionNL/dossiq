/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case type that processes special categories of personal data names them
 * (`caseType.specialDataCategories`), and its cases show one warning card per
 * category at the top of the side column (DqSociaalDomein "Bijzondere
 * persoonsgegevens: gezondheid."). Every category the register allows has its
 * card, every card names a category the register allows, and Melding
 * Jeugdwet lists health.
 *
 * @spec openspec/specs/case-detail/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const fragment = readJson('lib', 'Settings', 'register.d', '99-case-type-special-data.json')
const categories = fragment.components.schemas.caseType.properties.specialDataCategories.items.enum
const simple = readJson('src', 'menu-layout.simple.json')
const nl = readJson('l10n', 'nl.json').translations

/**
 * Every object in a tree.
 *
 * @param {unknown} node The tree.
 * @param {Array<object>} [out] The collected objects.
 * @return {Array<object>} The objects.
 */
function objects(node, out = []) {
	if (Array.isArray(node)) {
		node.forEach((item) => objects(item, out))
	} else if (node && typeof node === 'object') {
		out.push(node)
		Object.values(node).forEach((item) => objects(item, out))
	}
	return out
}

const cards = objects(simple).filter((item) => typeof item.id === 'string' && item.id.startsWith('side-special-data-'))

describe('special categories of personal data on a case', () => {
	it('declares the categories on the case type and re-imports it', () => {
		expect(categories).toEqual(['health', 'ethnicity', 'religion', 'politicalOpinion', 'criminal'])
		expect(fragment.components.schemas.caseType.version).toBe('1.27.0')
	})

	it('has one warning card per category, shown only when the case type lists it', () => {
		expect(cards.map((card) => card.id).sort()).toEqual(categories.map((key) => `side-special-data-${key}`).sort())
		for (const card of cards) {
			const key = card.id.replace('side-special-data-', '')
			expect(card.type).toBe('banner')
			expect(card.visibleWhen).toEqual({ field: '@context.caseType.specialDataCategories', op: 'contains', value: key })
			expect(card.content.variant).toBe('warning')
			expect(nl[card.content.lead]).toBe('Bijzondere persoonsgegevens:')
			expect(nl[card.content.text]).toMatch(/ Elke inzage wordt gelogd met het doel\.$/)
		}
	})

	it('puts the health card first beside a Melding Jeugdwet case', () => {
		const jeugdwet = readJson('lib', 'Settings', 'packages', 'jeugdwet', 'case-type.json').caseType
		expect(jeugdwet.specialDataCategories).toEqual(['health'])
		expect(jeugdwet.sideCards[0]).toBe('side-special-data-health')
		expect(nl['health. Every view is logged with its purpose.']).toBe('gezondheid. Elke inzage wordt gelogd met het doel.')
	})
})
