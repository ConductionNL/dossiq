/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards DqContact and DqOrganisatie end the side "Historie" card with
 * "Historie bekijken", which opens the record's history in the sidebar.
 *
 * @spec openspec/changes/party-history-link/specs/party-pages/spec.md#requirement-the-side-history-card-opens-the-full-history
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const pages = read('src/manifest.json').pages
const nl = read('l10n/nl.json').translations

describe.each(['ContactDetail', 'OrganisationDetail'])('%s side history', (id) => {
	const config = pages.find((page) => page.id === id).config
	const card = config.sideColumn.find((widget) => widget.id === 'side-history')

	it('links to the history tab of its own sidebar', () => {
		const tabs = config.sidebar.tabs.map((tab) => tab.id)
		expect(card.content.link.sidebarTab).toBe('audit')
		expect(tabs).toContain(card.content.link.sidebarTab)
		expect(card.content.link.icon).toBe('History')
	})

	it('reads "Historie bekijken"', () => {
		expect(nl[card.content.link.label]).toBe('Historie bekijken')
	})
})
