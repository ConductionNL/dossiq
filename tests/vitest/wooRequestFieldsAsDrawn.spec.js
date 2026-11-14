/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Woo request fields on the new-case form read as DqNieuweZaak draws
 * "Gegevens van het Woo-verzoek": the board's labels in the board's order,
 * and the choices as words ("Digitaal", not "digitaal").
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

/**
 * A register.d fragment.
 *
 * @param {string} name The file name.
 * @return {object} The fragment.
 */
function fragment(name) {
	return JSON.parse(readFileSync(new URL('../../lib/Settings/register.d/' + name, import.meta.url), 'utf8'))
}

const definitions = fragment('81-woo-verzoek-version-2.json').components.objects
	.filter((object) => object['@self']?.schema === 'propertyDefinition')
const byName = Object.fromEntries(definitions.map((definition) => [definition.name, definition]))

describe('the Woo request fields against DqNieuweZaak', () => {
	it('carry the board\'s labels in the board\'s order', () => {
		const drawn = ['onderwerp', 'verzoekerNaam', 'verzoekerEmail', 'verzoekerType', 'gewensteVorm', 'periodeVan', 'periodeTot', 'bestuurlijkeAangelegenheid']
		expect(drawn.map((name) => [byName[name].order, byName[name].title])).toEqual([
			[1, 'Onderwerp'], [2, 'Naam verzoeker'], [3, 'E-mail verzoeker'], [4, 'Soort verzoeker'],
			[5, 'Gewenste vorm'], [6, 'Periode van'], [7, 'Periode tot'], [8, 'Bestuurlijke aangelegenheid'],
		])
	})

	it('label every choice, and a field definition may carry option labels', () => {
		for (const name of ['verzoekerType', 'gewensteVorm']) {
			expect(Object.keys(byName[name].enumLabels).sort()).toEqual([...byName[name].enumValues].sort())
		}
		expect(byName.gewensteVorm.enumLabels.digitaal).toBe('Digitaal')
		const schema = fragment('69-field-option-labels.json').components.schemas.propertyDefinition
		expect(schema.properties.enumLabels.type).toBe('object')
	})
})
