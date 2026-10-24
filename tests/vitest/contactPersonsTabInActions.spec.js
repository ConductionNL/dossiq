/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqOrganisatie draws five tabs; the contact persons open from the
 * Actions menu and from the Hoofdcontact card.
 *
 * @spec openspec/changes/contact-persons-tab-in-actions/specs/party-pages/spec.md#requirement-an-organisations-contact-persons-open-from-the-actions-menu-and-the-main-contact-card
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const config = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
	.pages.find((page) => page.id === 'OrganisationDetail').config
const tabs = config.widgets.find((widget) => widget.id === 'contact-panels').content.tabs

describe('DqOrganisatie tab strip', () => {
	it('keeps five tabs in the strip and the contact persons in the Actions menu', () => {
		expect(tabs.filter((tab) => tab.overflow !== true)).toHaveLength(5)
		const persons = tabs.find((tab) => tab.widgetId === 'org-contact-persons')
		expect(persons.overflow).toBe(true)
		expect(persons.countFrom).toBeDefined()
	})

	it('lets the main contact card open it', () => {
		expect(config.sideColumn.find((entry) => entry.id === 'side-main-contact').content.link.tab).toBe('org-contact-persons')
	})
})
