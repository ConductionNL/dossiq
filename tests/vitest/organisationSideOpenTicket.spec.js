/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqOrganisatie's side column has "Open tickets" under "Lopende zaken":
 * the organisation's first open pipelinq ticket, drawn as the open case is.
 *
 * @spec openspec/changes/organisation-side-open-ticket/specs/party-pages/spec.md#requirement-the-organisation-page-shows-its-open-ticket-beside-its-open-case
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const config = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
	.pages.find((page) => page.id === 'OrganisationDetail').config
const side = config.sideColumn
const tickets = config.widgets.find((widget) => widget.id === 'contact-open-tickets')

describe('organisation side open ticket (DqOrganisatie)', () => {
	const card = side.find((widget) => widget.id === 'side-open-ticket')

	it('comes right after the open case', () => {
		const ids = side.map((widget) => widget.id)
		expect(ids.indexOf('side-open-ticket')).toBe(ids.indexOf('side-open-case') + 1)
	})

	it('reads the first open ticket from the same endpoint as the Tickets card, only with pipelinq', () => {
		expect(card.type).toBe('record-list')
		expect(card.title).toBe('Open tickets')
		expect(card.visibleWhen).toEqual({ appInstalled: 'pipelinq' })
		expect(card.content.requiresApp).toBe('pipelinq')
		expect(card.content.endpointSource.url).toBe(tickets.content.endpointSource.url)
		expect(card.content.endpointSource.params).toEqual({ key: tickets.content.endpointSource.params.key })
		expect(card.content.limit).toBe(1)
	})

	it('draws the ticket as the Tickets card does', () => {
		expect(card.content.pill).toEqual(tickets.content.pill)
		expect(card.content.valueLabels).toEqual(tickets.content.valueLabels)
		expect(card.content.trailing).toEqual(tickets.content.trailing)
	})
})
