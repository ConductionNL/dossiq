/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTenant's side Historie card lists the latest events, what happened, who
 * and when, as the Historie tab does in full: the two newest entries of the
 * organisation's audit trail, and a link to the tab.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const config = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.d/67-environment-organisations.json'), 'utf8'))
	.pages.find((entry) => entry.id === 'EnvironmentOrganisationDetail').config
const side = config.sideColumn.find((entry) => entry.id === 'organisation-side-history')
const tab = config.widgets.find((entry) => entry.id === 'organisation-history')

describe('organisation side history (DqTenant)', () => {
	it('lists the two newest events the History tab lists', () => {
		expect(side.type).toBe('record-list')
		expect(side.content.endpointSource).toEqual(tab.content.endpointSource)
		expect(side.content.limit).toBe(2)
		expect(side.content.title).toBe(tab.content.title)
		expect(side.content.meta).toBe(tab.content.meta)
		expect(side.content.valueLabels).toEqual(tab.content.valueLabels)
	})

	it('draws its rows as DqTenant does: ruled above the first, 10px above and below each', () => {
		expect(side.content.ruleAbove).toBe(true)
		expect(side.content.density).toBe('compact')
	})

	it('names the creation of the organisation, as the board\'s "Organisatie aangemaakt"', () => {
		expect(tab.content.endpointSource.url).toContain('action=organisation.status_changed,organisation.updated,create&')
		expect(tab.content.valueLabels.action.create).toBe('Organisation created')
		const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations
		const en = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/en.json'), 'utf8')).translations
		expect(nl['Organisation created']).toBe('Organisatie aangemaakt')
		expect(en['Organisation created']).toBe('Organisation created')
	})

	it('keeps its title Historie', () => {
		expect(side.title).toBe('Record history')
	})
})
