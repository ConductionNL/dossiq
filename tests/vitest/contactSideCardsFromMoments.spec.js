/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqContact: Identiteit opens with how the person was identified at the
 * newest contact moment, Bereikbaar via says when the last contact was.
 *
 * @spec openspec/changes/contact-side-cards-from-moments/specs/party-pages/spec.md#requirement-a-contacts-side-cards-read-the-newest-contact-moment
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const pages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations
const side = pages.find((page) => page.id === 'ContactDetail').config.sideColumn
const card = (id) => side.find((entry) => entry.id === id).content

describe('DqContact side cards', () => {
	it('Identity reads the newest identified contact moment and keeps the person\'s rows', () => {
		const content = card('side-identity')
		expect(content.source).toMatchObject({ register: 'dossiq', schema: 'contactmoment', filter: { contact: '@objectId' }, sort: { field: 'startTime', dir: 'desc' } })
		const lead = content.fields.find((field) => field.as === 'lead')
		expect(lead.from).toBeUndefined()
		expect(nl[lead.title[0].map.digid]).toBe('Ingelogd met DigiD')
		expect(lead.title[0].map.non_geidentificeerd).toBe('')
		expect(nl[lead.subtitle[0].label]).toBe('bij het contactmoment van')
		const own = content.fields.filter((field) => field.as !== 'lead' && field.label)
		expect(own.map((field) => field.label)).toEqual(['BSN', 'Data protection', 'Source'])
		expect(own.every((field) => field.from === 'object')).toBe(true)
		expect(nl.Source).toBe('Bron')
	})

	it('Reachable via reads the reachability endpoint: portal mailbox, last contact, registration line', () => {
		const content = card('side-reach')
		expect(content.endpointSource.url).toBe('/apps/dossiq/api/people/@objectId/reachability')
		const [portal, last, registration] = content.fields
		expect(portal).toMatchObject({ path: 'portal', icon: 'EmailOutline' })
		expect(last.parts.map((part) => part.path)).toEqual(['lastContactAt', 'lastContactChannel'])
		expect(nl[last.parts[0].label]).toBe('Laatst contact op')
		expect(nl[last.parts[1].map.webformulier]).toBe('webformulier')
		expect(registration.from).toBe('object')
		expect(content.action.props.contact).toBe('@objectId')
		expect(nl['%s, message box']).toBe('%s, berichtenbox')
	})
})
