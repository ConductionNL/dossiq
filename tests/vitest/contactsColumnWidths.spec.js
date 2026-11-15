/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqContacten sizes its columns: Naam 250px (room for "Kerkplein 3,
 * Zuiddrecht" under the name), BSN 211px, Woonplaats 157px, Omschrijving the
 * rest; Omschrijving sorts.
 *
 * @spec openspec/changes/contacts-list-columns-as-drawn/specs/party-pages/spec.md#requirement-the-contacts-list-sizes-its-columns-as-the-board
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const contacts = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
	.pages.find((page) => page.id === 'Contacts' && page.config.schema === 'brpPerson').config
const column = (key) => contacts.columns.find((entry) => entry.key === key)

describe('contacts list columns (DqContacten)', () => {
	it('gives each column the board\'s width and leaves the rest to the description', () => {
		expect(column('displayName').width).toBe('250px')
		expect(column('citizenServiceNumber').width).toBe('211px')
		expect(column('residence.city').width).toBe('157px')
		expect(column('description').width).toBeUndefined()
	})

	it('sorts on the description', () => {
		expect(column('description').sortable).not.toBe(false)
	})
})
