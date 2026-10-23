/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTenant's Quota card: Zaken "1.240 van 5.000", Opslag, Gebruikers "24 van
 * 50". OpenRegister's organisation quota counts the objects of the schema
 * its object quota names (the cases here) and limits its users.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const config = read('src/manifest.d/67-environment-organisations.json').pages.find((entry) => entry.id === 'EnvironmentOrganisationDetail').config
const fields = config.widgets.find((widget) => widget.id === 'organisation-quota').content.fields
const nl = read('l10n/nl.json').translations

describe('organisation quota (DqTenant)', () => {
	it('runs cases, storage, users, as the board does, and keeps the requests after them', () => {
		expect(fields.map((field) => field.key)).toEqual(['cases', 'storage', 'users', 'requests'])
	})

	it('reads cases and users against their limits', () => {
		const cases = fields.find((field) => field.key === 'cases')
		const users = fields.find((field) => field.key === 'users')
		expect([cases.path, cases.limitPath]).toEqual(['usage.objects', 'quota.objects'])
		expect([users.path, users.limitPath]).toEqual(['usage.users', 'quota.users'])
		expect(nl[cases.label]).toBe('Zaken')
	})
})
