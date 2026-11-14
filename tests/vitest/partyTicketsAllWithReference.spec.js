/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards DqContact and DqOrganisatie: the Tickets card lists a party's handled
 * tickets under the open ones, each as "PQ-2026-0402 · Aanvraag · behandelaar
 * Fatma Yildiz", a handled one green with "Klaar <date>".
 *
 * @spec openspec/changes/party-tickets-all-with-reference/specs/pipelinq-consumption/spec.md#requirement-a-partys-tickets-card-lists-its-handled-tickets-too
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const pages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations
const config = (id) => pages.find((page) => page.id === id).config

describe.each(['ContactDetail', 'OrganisationDetail'])('%s Tickets card', (pageId) => {
	const card = config(pageId).widgets.find((widget) => widget.id === 'contact-open-tickets').content

	it('asks for all of the party\'s tickets', () => {
		expect(card.endpointSource.params.status).toBe('all')
	})

	it('reads reference, type and handler, and a handled ticket green with its done date', () => {
		expect(card.meta.parts).toEqual(['{reference}', '{ticketType}', 'handler {assigneeName}'])
		expect(card.pill.colorMap.resolved).toBe('success')
		expect(card.trailing[0]).toBe('Done {resolvedAt|date}')
		expect(nl['handler {assigneeName}']).toBe('behandelaar {assigneeName}')
		expect(nl['Done {resolvedAt|date}']).toBe('Klaar {resolvedAt|date}')
	})
})

describe('OrganisationDetail open ticket side card', () => {
	it('reads the reference and the handler', () => {
		const side = config('OrganisationDetail').sideColumn.find((widget) => widget.id === 'side-open-ticket').content
		expect(side.meta.parts).toEqual(['{reference}', 'handler {assigneeName}'])
	})
})
