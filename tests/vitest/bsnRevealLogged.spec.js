/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqContact: "Tonen" shows the BSN, and "Het tonen van het BSN wordt
 * gelogd." is true: the BSN arrives masked and Tonen reads it from OpenRegister
 * with _reveal, which checks and records the look.
 *
 * @spec openspec/changes/bsn-reveal-logged/specs/party-pages/spec.md#requirement-the-bsn-is-shown-through-a-logged-server-reveal
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const pages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations
const schemas = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib/Settings/register.d/25-brp-kvk.json'), 'utf8')).components.schemas

describe('DqContact BSN reveal', () => {
	it('declares the BSN audited and explicit-reveal, read only by the sensitive group', () => {
		const authorization = schemas.brpPerson.properties.citizenServiceNumber.authorization
		expect(authorization).toEqual({ read: [{ group: 'dossiq-sensitive' }], audit: true, reveal: 'explicit' })
	})

	it('Tonen asks OpenRegister for the value and the card says it is logged', () => {
		const fields = pages.find((page) => page.id === 'ContactDetail').config.sideColumn.find((entry) => entry.id === 'side-identity').content.fields
		const bsn = fields.find((field) => field.path === 'citizenServiceNumber')
		expect(bsn.reveal).toEqual({ url: '/apps/openregister/api/objects/dossiq/brpPerson/@objectId?_reveal=citizenServiceNumber', path: 'citizenServiceNumber' })
		const line = fields[fields.length - 1]
		expect(line).toMatchObject({ as: 'line', from: 'object' })
		expect(nl[line.text]).toBe('Het tonen van het BSN wordt gelogd.')
	})

	/** @spec openspec/changes/identity-bsn-inline/specs/party-pages/spec.md#requirement-the-bsn-and-tonen-share-one-line */
	it('draws the BSN, its mask and Tonen on one line across the card', () => {
		const fields = pages.find((page) => page.id === 'ContactDetail').config.sideColumn.find((entry) => entry.id === 'side-identity').content.fields
		expect(fields.find((field) => field.path === 'citizenServiceNumber').inline).toBe(true)
	})
})
