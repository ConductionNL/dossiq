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

describe.each(['ContactDetail', 'OrganisationDetail'])('%s overview', (id) => {
	it('puts the tickets before the latest contact moments', () => {
		const config = pages.find((page) => page.id === id).config
		const overview = config.widgets.find((widget) => widget.type === 'tabs').content.tabs[0]
		expect(overview.widgetIds).toEqual(['contact-card', 'contact-open-cases', 'contact-open-tickets', 'contact-latest-moments'])
	})
})
