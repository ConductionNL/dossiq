/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards DqContact and DqOrganisatie rule a line under the title of Zaken,
 * Tickets and Laatste contactmomenten, above the first row.
 *
 * @spec openspec/specs/party-pages/spec.md#requirement-a-partys-list-cards-rule-above-their-first-row
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const pages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages

describe.each(['ContactDetail', 'OrganisationDetail'])('%s list cards', (id) => {
	it.each(['contact-open-cases', 'contact-open-tickets', 'contact-latest-moments'])('%s rules above its first row', (widgetId) => {
		const widget = pages.find((page) => page.id === id).config.widgets.find((entry) => entry.id === widgetId)
		expect(widget.content.ruleAbove).toBe(true)
	})
})
