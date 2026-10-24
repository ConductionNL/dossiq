/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTenant's details: the Status card names the slug "Kenmerk", billing
 * amounts read as money with the line that events never change under the
 * table, and the quota rows run storage before users.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const config = read('src/manifest.d/67-environment-organisations.json').pages.find((entry) => entry.id === 'EnvironmentOrganisationDetail').config
const nl = read('l10n/nl.json').translations
const widget = (id) => config.widgets.find((entry) => entry.id === id)

describe('organisation page details (DqTenant)', () => {
	it('labels the slug Kenmerk in the Status card', () => {
		const status = config.sideColumn.find((entry) => entry.id === 'organisation-side-status')
		const slug = status.content.fields.find((field) => field.path === 'slug')
		expect(nl[slug.label]).toBe('Kenmerk')
	})

	it.each(['organisation-billing', 'organisation-billing-list'])('%s shows amounts as money, dates as dates and the footnote', (id) => {
		const content = widget(id).content
		expect(content.columns.find((column) => column.key === 'unitPrice').formatter).toBe('currency')
		expect(content.columns.find((column) => column.key === 'occurredAt').formatter).toBe('date')
		expect(nl[content.footnote]).toBe('Gebeurtenissen veranderen niet achteraf; een correctie is een nieuwe regel.')
		expect(nl[content.emptyText]).toBe('Nog geen factuurgebeurtenissen')
	})

	it('runs the quota rows storage before users', () => {
		const keys = widget('organisation-quota').content.fields.map((field) => field.key)
		expect(keys.indexOf('storage')).toBeLessThan(keys.indexOf('users'))
	})
})
