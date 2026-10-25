/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktype "Berichten aan de aanvrager": the case type's messages as a
 * table of when each goes out, how, and which message, with the opt-out line
 * under it. The template editor stays on the page, on a Message templates
 * tab under the tab strip's Actions menu, and Add a message opens it.
 *
 * @spec openspec/changes/case-type-messages-moment-channel/specs/case-types/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { labelMap, MESSAGE_CHANNELS, MESSAGE_MOMENTS } from '../../src/utils/messageDelivery.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const page = readJson('src', 'menu-layout.simple.json').pages.find((entry) => entry.id === 'CaseTypeDetail')
const widget = (id) => page.configAppend.widgets.find((entry) => entry.id === id)
const tabs = widget('case-type-panels').content.tabs
const tab = (id) => tabs.find((entry) => entry.id === id)
const schema = readJson('lib', 'Settings', 'register.d', '99-email-template-moment-and-channel.json').components.schemas.emailTemplate
const nl = readJson('l10n', 'nl.json').translations

describe('Messages to the applicant (DqZaaktype)', () => {
	const messages = widget('case-type-messages')

	it('lists the active templates of this case type as Moment, Channel, Message', () => {
		expect(messages.title).toBe('Messages to the applicant')
		expect(messages.content).toMatchObject({ register: 'dossiq', schema: 'emailTemplate', filter: { caseType: '@objectId', isActive: true }, presentation: 'table' })
		expect(messages.content.columns).toEqual([
			{ label: 'Moment', value: '{moment}' },
			{ label: 'Channel', value: '{channel}' },
			{ label: 'Message', value: '{name}' },
		])
	})

	it('names every moment and channel the schema allows, as the editor does', () => {
		expect(messages.content.valueLabels.moment).toEqual(labelMap(MESSAGE_MOMENTS))
		expect(messages.content.valueLabels.channel).toEqual(labelMap(MESSAGE_CHANNELS))
		expect(Object.keys(messages.content.valueLabels.moment)).toEqual(schema.properties.moment.enum)
		expect(Object.keys(messages.content.valueLabels.channel)).toEqual(schema.properties.channel.enum)
		;[...MESSAGE_MOMENTS, ...MESSAGE_CHANNELS].forEach((option) => expect(nl[option.label]).toBeTruthy())
	})

	it('says what an opted-out applicant still receives', () => {
		expect(nl[messages.content.footnote]).toBe('Heeft iemand zich afgemeld voor berichten, dan krijgt die alleen nog wat de wet verplicht. Elke e-mail heeft een afmeldlink.')
	})

	it('is drawn as the board\'s data table, the opt-out note in an info box', () => {
		expect(messages.content.tableLook).toBe('data')
		expect(messages.content.footnoteVariant).toBe('info')
	})

	it('sits on Phases and tasks, with the editor on an overflow tab that Add a message opens', () => {
		expect(tab('phases').widgetIds).toContain('case-type-messages')
		expect(tab('phases').widgetIds).not.toContain('case-type-messages-editor')
		expect(tab('messages')).toMatchObject({ label: 'Message templates', overflow: true, widgetIds: ['case-type-messages-editor'] })
		expect(messages.headerActions[0]).toMatchObject({ label: 'Add a message', action: { type: 'navigate', target: '/settings/case-types/@objectId#messages' } })
	})
})
