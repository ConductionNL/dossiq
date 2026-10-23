/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTenant's Facturatie table names each event in words ("Zaak aangemaakt",
 * "Terugboeking", "Maandbedrag pakket"), not as its stored code. Every event
 * type the schema allows has a name, on the Overview card and the tab.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const config = read('src/manifest.d/67-environment-organisations.json').pages.find((entry) => entry.id === 'EnvironmentOrganisationDetail').config
const types = read('lib/Settings/dossiq_register.json').components.schemas.tenantBillingEvent.properties.eventType.enum
const nl = read('l10n/nl.json').translations

describe('billing event names (DqTenant)', () => {
	it.each(['organisation-billing', 'organisation-billing-list'])('%s names every event type in Dutch', (id) => {
		const column = config.widgets.find((widget) => widget.id === id).content.columns.find((col) => col.key === 'eventType')
		expect(Object.keys(column.valueLabels).sort()).toEqual([...types].sort())
		Object.values(column.valueLabels).forEach((label) => expect(nl[label]).toBeTruthy())
		expect(nl[column.valueLabels.subscription_fee]).toBe('Maandbedrag pakket')
	})
})
