/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqContact: the Woonadres card ends in "Zaken op de kaart". The facts
 * widget draws a link only for a route OBJECT (`{ name, query }`); the bare
 * page name it had was dropped without a sound. The link opens this
 * relation's own cases in the cases list's map view.
 *
 * @spec openspec/specs/initiator-display/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))

describe('the address card on a relation page', () => {
	it.each([['ContactDetail', 'side-address'], ['OrganisationDetail', 'side-kvk']])('%s links to its own cases on the map', (id, cardId) => {
		const page = manifest.pages.find((candidate) => candidate.id === id)
		const card = page.config.sideColumn.find((item) => item.id === cardId)

		expect(card.content.link).toEqual({
			label: 'Cases on the map',
			route: { name: 'Cases', query: { _view: 'map', requester: '@objectId' } },
			icon: 'MapMarkerOutline',
		})
	})
})
