/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqNieuweZaak: the Woo request asks the requester's kind and the form of
 * delivery as choices, as the board draws them (Gewenste vorm "Digitaal",
 * Soort verzoeker "Burger"), not as free text.
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const read = (p) => JSON.parse(readFileSync(new URL('../../' + p, import.meta.url), 'utf8'))
const fields = (doc) => JSON.stringify(doc)
const SOURCES = ['lib/Settings/register.d/81-woo-verzoek-version-2.json', 'lib/Settings/register.d/81-woo-verzoek.json', 'lib/Settings/templates/woo-verzoek.json']

describe('the Woo request choice fields', () => {
	it.each(SOURCES)('%s offers the delivery form and the requester kind as choices', (p) => {
		const text = fields(read(p))
		expect(text).toMatch(/"name":"gewensteVorm"[^}]*"propertyType":"enum","enumValues":\["papier","digitaal","inzage"\]/)
		expect(text).toMatch(/"name":"verzoekerType"[^}]*"propertyType":"enum","enumValues":\["burger","journalist","organisatie"\]/)
	})
})
