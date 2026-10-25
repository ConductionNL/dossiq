/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact moments on the case's Contact tab read as words and dates
 * ("10 sep, 10:05", "Telefoon", "Inkomend"), not as the stored codes
 * ("2026-09-10T08:05:00+00:00", "phone", "inbound").
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const card = simple.configAppend.widgets.find((widget) => widget.id === 'case-contact-moments-card')
const schema = read('lib/Settings/register.d/40-kcc-werkplek.json').components.schemas.contactmoment.properties
const nl = read('l10n/nl.json').translations
const column = (key) => card.content.columns.find((entry) => entry.key === key)

describe('the contact moments on the Contact tab', () => {
	it('dates each moment', () => {
		expect(column('startTime').format).toBe(schema.startTime.format)
	})

	it('reads as a plain log: the date with its time as regular text, the channel as an icon and its name', () => {
		expect(card.content.rowTitle).toBe('text')
		expect(column('startTime').widget).toBe('date')
		expect(column('startTime').widgetProps).toEqual({ short: true, showTime: true })
		const channel = column('notificationChannel')
		expect(channel.widget).toBe('iconLabel')
		const icons = fs.readFileSync(path.join(ROOT, 'src/icons.js'), 'utf8')
		for (const value of schema.notificationChannel.enum) {
			const icon = channel.widgetProps.icons[value]
			expect(icon, value).toBeTruthy()
			expect(icons, icon).toMatch(new RegExp(`^\\s+${icon},$`, 'm'))
		}
	})

	it('names every channel and direction the schema allows, in Dutch too', () => {
		for (const key of ['notificationChannel', 'direction']) {
			expect(column(key).enum).toEqual(schema[key].enum)
			for (const value of schema[key].enum) {
				// `config:portalName|Web form`: the portal's name, else the fallback label.
				const label = String(column(key).enumLabels[value]).replace(/^config:\w+\|/, '')
				expect(label, `${key}.${value}`).toBeTruthy()
				expect(nl[label], label).toBeTruthy()
			}
		}
	})

	it('sorts and filters by every column and wraps the summary, as the board draws its head and rows', () => {
		expect(card.content.sortable).toBe(true)
		expect(card.content.filterable).toBe(true)
		expect(column('summary').cellClass).toBe('cn-cell--wrap')
	})
})

describe('the answer to the resident', () => {
	it('can be kept as a draft on the case (DqZaakContact "Opslaan als concept")', () => {
		const thread = simple.configAppend.widgets.find((widget) => widget.id === 'case-resident-thread')
		expect(thread.content.draft).toEqual({ url: '/apps/dossiq/api/cases/@objectId/reply-draft' })
	})
})
