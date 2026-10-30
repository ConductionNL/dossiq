/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A manifest label written as `@config.<key>|<fallback>` reads the app
 * config (the portal channel named after the portal, "Mijn Zuiddrecht"),
 * and the fallback when the setting is empty.
 *
 * @spec openspec/specs/portal-channel-label/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { withConfigLabels } from '../../src/utils/configLabels.js'

const simple = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8')).pages.find((page) => page.id === 'CaseDetail')
const widget = (id) => simple.configAppend.widgets.find((entry) => entry.id === id)

describe('withConfigLabels', () => {
	it('puts the config value in, or the fallback when it is empty', () => {
		const manifest = { pages: [{ config: { map: { webformulier: 'config:portalName|Web form', phone: 'Phone' } } }] }
		expect(withConfigLabels(structuredClone(manifest), { portalName: 'Mijn Zuiddrecht' }).pages[0].config.map).toEqual({ webformulier: 'Mijn Zuiddrecht', phone: 'Phone' })
		expect(withConfigLabels(structuredClone(manifest), { portalName: '  ' }).pages[0].config.map.webformulier).toBe('Web form')
		expect(withConfigLabels(structuredClone(manifest), {}).pages[0].config.map.webformulier).toBe('Web form')
	})

	it('leaves other tokens and texts alone', () => {
		const manifest = { pages: [{ filter: { case: '@objectId', who: '@config.city' } }] }
		expect(withConfigLabels(structuredClone(manifest), { city: 'Zuiddrecht' }).pages[0].filter).toEqual({ case: '@objectId', who: '@config.city' })
	})
})

describe('the conversation channels (DqZaakContact)', () => {
	// @spec openspec/specs/conversation-channel-icons/spec.md#requirement-the-conversation-chips-carry-the-channel-icons
	it('carry the same icon as the contact-moments Kanaal column', () => {
		const column = widget('case-contact-moments-card').content.columns.find((entry) => entry.key === 'notificationChannel')
		expect(widget('case-resident-thread').content.channelIcons).toEqual(column.widgetProps.icons)
	})
})

describe('the conversation message lines (DqZaakContact)', () => {
	// @spec openspec/specs/contact-message-subject-note/spec.md#requirement-a-contact-moment-carries-its-subject-note-and-attachment-count
	it('reads the subject above the summary and the note with the attachment count under it', () => {
		const content = widget('case-resident-thread').content
		// The full message where the contact has one, else its summary; the table keeps the summary.
		// @spec openspec/changes/contact-message-text/specs/contact-message-text/spec.md#requirement-a-contact-moment-carries-its-full-message
		expect(content.bodyField).toEqual(['message', 'summary'])
		expect(content.subjectField).toBe('subject')
		expect(content.noteField).toBe('note')
		expect(content.attachmentCountField).toBe('attachmentCount')
	})

	it('declares those fields on the contact moment', () => {
		const schema = require('../../lib/Settings/register.d/40-kcc-werkplek.json').components.schemas.contactmoment
		expect(schema.properties.subject.type).toBe('string')
		expect(schema.properties.message.type).toBe('string')
		expect(schema.properties.note.type).toBe('string')
		expect(schema.properties.attachmentCount).toMatchObject({ type: 'integer', minimum: 0 })
	})
})

describe('the portal channel on the case page (DqZaakContact)', () => {
	it('is named after the portal in the chips and the contact-moments table', () => {
		expect(widget('case-resident-thread').content.channelMap.webformulier).toBe('config:portalName|Web form')
		const column = widget('case-contact-moments-card').content.columns.find((entry) => entry.key === 'notificationChannel')
		expect(column.enumLabels.webformulier).toBe('config:portalName|Web form')
	})
})
