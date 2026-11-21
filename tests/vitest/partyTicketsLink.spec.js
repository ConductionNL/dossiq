/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards DqContact and DqOrganisatie head the Tickets card with "Alle 2
 * tickets bekijken", which opens the party's tickets in pipelinq.
 *
 * @spec openspec/changes/party-tickets-link/specs/party-pages/spec.md#requirement-the-tickets-card-links-to-the-partys-tickets-in-pipelinq
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const pages = read('src/manifest.json').pages
const nl = read('l10n/nl.json').translations

describe.each(['ContactDetail', 'OrganisationDetail'])('%s Tickets card', (id) => {
	const card = pages.find((page) => page.id === id).config.widgets.find((widget) => widget.id === 'contact-open-tickets')

	it('links to the party\'s tickets in pipelinq by its master entity', () => {
		expect(card.content.headerLink).toEqual({
			label: 'View all {total} tickets',
			href: '/apps/pipelinq/tickets/party/{ref}',
			params: { ref: '@object.masterEntityRef' },
		})
		expect(nl[card.content.headerLink.label]).toBe('Alle {total} tickets bekijken')
	})

	it('names the count in the link, not in a note beside it', () => {
		expect(card.content.headerNote).toBeUndefined()
	})
})
