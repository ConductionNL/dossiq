/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tenant's Administrator card names the organisation's admin user, the
 * tenantUser with role admin, as DqTenant draws it ("Beheerder: MV Marieke
 * Visser", a person chip). The organisation itself has no owner field anything writes.
 *
 * @spec exclude board DqTenant Beheerder card source, configuration only
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const fragment = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.d/67-environment-organisations.json'), 'utf8'))
const page = fragment.pages.find((entry) => entry.id === 'EnvironmentOrganisationDetail')
const card = page.config.sideColumn.find((widget) => widget.id === 'organisation-side-admin')

describe('the tenant Administrator card', () => {
	it('reads the organisation\'s admin user', () => {
		expect(card.content.source).toMatchObject({
			register: 'dossiq',
			schema: 'tenantUser',
			filter: { tenantRef: '@objectId', role: 'admin' },
		})
		expect(card.content.source.id).toBeUndefined()
	})

	it('draws that user as a person chip, the organisation\'s contact address under it', () => {
		const [chip, email] = card.content.fields
		expect(chip).toMatchObject({ as: 'lead', title: { path: 'userRef', format: 'user' }, initialsFrom: 'title', size: 28, tone: 'teal' })
		expect(chip.from).toBeUndefined()
		expect(email).toMatchObject({ as: 'line', path: 'contactEmail', from: 'object' })
	})
})
