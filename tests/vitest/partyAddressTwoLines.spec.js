/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards DqContact and DqOrganisatie write the address as two lines of one
 * block and put a map pin before "Zaken op de kaart".
 *
 * @spec openspec/specs/party-pages/spec.md#requirement-a-partys-address-is-two-lines-of-one-block
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const pages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages

describe.each([['ContactDetail', 'side-address'], ['OrganisationDetail', 'side-kvk']])('%s %s', (pageId, cardId) => {
	const card = pages.find((page) => page.id === pageId).config.sideColumn.find((widget) => widget.id === cardId)

	it('joins the postcode line to the street line with a break', () => {
		const texts = card.content.fields.filter((field) => field.as === 'text')
		expect(texts).toHaveLength(2)
		expect(texts[1]).toMatchObject({ joinPrevious: true, joinSeparator: '\n' })
	})

	it('puts a map pin before the map link', () => {
		expect(card.content.link.icon).toBe('MapMarkerOutline')
	})
})
