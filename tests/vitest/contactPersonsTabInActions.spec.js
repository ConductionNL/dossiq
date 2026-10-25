/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqOrganisatie draws six tabs, Contactpersonen among them; the
 * Hoofdcontact card opens that tab too.
 *
 * @spec openspec/changes/organisation-contact-persons-tab/specs/party-pages/spec.md#requirement-an-organisations-contact-persons-have-their-own-tab
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const config = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
	.pages.find((page) => page.id === 'OrganisationDetail').config
const tabs = config.widgets.find((widget) => widget.id === 'contact-panels').content.tabs

describe('DqOrganisatie tab strip', () => {
	it('draws the board\'s six tabs in the strip, contact persons after contact moments', () => {
		expect(tabs.filter((tab) => tab.overflow !== true).map((tab) => tab.widgetId ?? 'overview'))
			.toEqual(['overview', 'contact-cases', 'contact-moments', 'org-contact-persons', 'contact-documents', 'contact-history'])
		expect(tabs.find((tab) => tab.widgetId === 'org-contact-persons').countFrom).toBeDefined()
	})

	it('lets the main contact card open it', () => {
		expect(config.sideColumn.find((entry) => entry.id === 'side-main-contact').content.link.tab).toBe('org-contact-persons')
	})
})
