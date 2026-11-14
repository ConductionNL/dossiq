/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards DqContact and DqOrganisatie stack the Overview as Contact or
 * Organisation, Zaken, Tickets, then Laatste contactmomenten.
 *
 * @spec openspec/changes/party-overview-card-order/specs/party-pages/spec.md#requirement-the-party-overview-shows-tickets-before-the-contact-moments
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const pages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages

describe('OrganisationDetail contact persons', () => {
	it('sits between the tickets and the latest contact moments, as DqOrganisatie draws', () => {
		const overview = pages.find((page) => page.id === 'OrganisationDetail').config.widgets.find((widget) => widget.type === 'tabs').content.tabs[0]
		expect(overview.widgetIds.slice(2)).toEqual(['contact-open-tickets', 'org-contact-persons', 'contact-latest-moments'])
	})
})

describe('ContactDetail contact card', () => {
	it('names the account owner and shows four contact moments, as DqContact draws', () => {
		const config = pages.find((page) => page.id === 'ContactDetail').config
		const fields = config.widgets.find((widget) => widget.id === 'contact-card').content.fields
		expect(fields.find((field) => field.label === 'Account owner').parts[0]).toEqual({ path: 'accountOwner', format: 'user' })
		expect(config.widgets.find((widget) => widget.id === 'contact-latest-moments').content.limit).toBe(4)
	})
})

describe.each(['ContactDetail', 'OrganisationDetail'])('%s overview', (id) => {
	it('puts the tickets before the latest contact moments', () => {
		const config = pages.find((page) => page.id === id).config
		const overview = config.widgets.find((widget) => widget.type === 'tabs').content.tabs[0]
		expect(overview.widgetIds.filter((widgetId) => widgetId !== 'org-contact-persons')).toEqual(['contact-card', 'contact-open-cases', 'contact-open-tickets', 'contact-latest-moments'])
	})
})
