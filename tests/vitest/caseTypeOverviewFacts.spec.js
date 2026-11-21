/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaaktypeOverzicht: the Gegevens card ends with the Iv3 task field,
 * the colour and icon and the queue thresholds, the Versies card explains
 * the download, the Statussen card carries both notes, and the rule rows can
 * name the messages their case type holds.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import layout from '../../src/menu-layout.simple.json'

const registered = []
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({ registerObjectType: (type) => registered.push(type) }),
}))
vi.mock('../../src/store/modules/settings.js', () => ({
	useSettingsStore: () => ({ fetchSettings: async () => ({ register: 'dossiq' }) }),
}))

const page = layout.pages.find((p) => p.id === 'CaseTypeDetail')
const side = (id) => page.config.sideColumn.find((w) => w.id === id)

describe('the case type overview cards', () => {
	it('adds the Iv3, colour and queue entries to the Gegevens card', () => {
		const fields = side('side-case-type-facts').content.fields
		expect(fields.find((f) => f.path === 'iv3TaskField')).toMatchObject({ label: 'Iv3 task field' })
		expect(fields.find((f) => f.as === 'pill')).toMatchObject({ path: 'colour', template: '{title}' })
		expect(fields.find((f) => f.label === 'Queue').template).toContain('{queueCriticalDays}')
	})

	it('explains the download under Publish', () => {
		const publish = side('side-case-type-versions').content.footerAction.find((a) => a.label === 'Publish')
		expect(publish.note).toContain('whole case type as a file')
	})

	it('draws the Statussen notes as two paragraphs', () => {
		const statuses = page.configAppend.widgets.find((w) => w.id === 'case-type-statuses')
		expect(statuses.content.footnote).toHaveLength(2)
	})

	it('registers the email templates the rule rows name', async () => {
		const { initializeStores } = await import('../../src/store/store.js')
		await initializeStores()
		expect(registered).toContain('emailTemplate')
	})
})
