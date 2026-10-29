/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tenant's Administrator card names the organisation's admin user, the
 * tenantUser with role admin, as DqTenant draws it ("Beheerder: Marieke
 * Visser"). The organisation itself has no owner field anything writes.
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

	it('names that user, and the organisation\'s contact address', () => {
		const owner = card.content.fields.find((field) => field.format === 'user')
		expect(owner.path).toBe('userRef')
		expect(owner.from).toBeUndefined()

		const email = card.content.fields.find((field) => field.format === 'email')
		expect(email).toMatchObject({ path: 'contactEmail', from: 'object' })
	})
})
