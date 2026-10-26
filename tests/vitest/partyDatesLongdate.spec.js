/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards DqContact and DqOrganisatie write side-card dates with the month
 * spelled out: "Laatst gewijzigd op 3 oktober", "bij het contactmoment van 4 oktober".
 *
 * @spec openspec/changes/party-dates-longdate/specs/party-pages/spec.md#requirement-a-partys-side-cards-spell-the-month-out
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const pages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages
const side = (id) => pages.find((page) => page.id === id).config.sideColumn

describe('party side-card dates', () => {
	it.each(['ContactDetail', 'OrganisationDetail'])('%s Historie spells the month out', (id) => {
		const parts = side(id).find((entry) => entry.id === 'side-history').content.fields[0].parts
		expect(parts.find((part) => part.path === '@self.updated').format).toBe('longdate')
	})

	it('the Identity lead and Bereikbaar via spell the month out', () => {
		const identity = side('ContactDetail').find((entry) => entry.id === 'side-identity').content.fields[0]
		expect(identity.subtitle[0].format).toBe('longdate')
		const reach = side('ContactDetail').find((entry) => entry.id === 'side-reach').content.fields.find((field) => Array.isArray(field.parts))
		expect(reach.parts[0].format).toBe('longdate')
	})
})
