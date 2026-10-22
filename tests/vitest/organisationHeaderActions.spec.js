/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An organisation can be suspended, resumed and ended from its page, through
 * OpenRegister's organisation lifecycle (board DqTenant).
 *
 * @spec openspec/changes/organisation-header-actions/specs/organisation-header-actions/spec.md#requirement-an-organisation-can-be-suspended-resumed-and-ended-from-its-page
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const fragment = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.d/67-environment-organisations.json'), 'utf8'))
const config = fragment.pages.find((entry) => entry.id === 'EnvironmentOrganisationDetail').config
const action = (id) => config.headerActions.find((entry) => entry.id === id)

describe('organisation lifecycle actions', () => {
	it.each([
		['organisation-suspend', 'suspend', 'active'],
		['organisation-resume', 'activate', 'suspended'],
		['organisation-end', 'deprovision', undefined],
	])('%s calls OpenRegister %s with PUT', (id, verb, status) => {
		const entry = action(id)
		expect(entry.type).toBe('api-call')
		expect(entry.method).toBe('PUT')
		expect(entry.url).toBe(`/apps/openregister/api/organisations/@objectId/${verb}`)
		expect(entry.visibleWhen?.value).toBe(status)
	})

	it('shows Suspend, Resume and Edit in the header and End under More, confirmed', () => {
		expect(config.quickActions).toEqual(['organisation-suspend', 'organisation-resume', 'organisation-edit'])
		expect(action('organisation-end').confirm).toBe(true)
		expect(action('organisation-suspend').confirm).toBe(true)
	})
})

/**
 * @spec openspec/changes/organisation-header-actions/specs/organisation-header-actions/spec.md#requirement-an-organisations-name-and-description-can-be-edited-from-its-page
 */
describe('editing an organisation', () => {
	it('edits the name and description through OpenRegister\'s organisation endpoint', () => {
		const entry = action('organisation-edit')
		expect(entry.type).toBe('open-form')
		expect(entry.endpoint).toEqual({ url: '/apps/openregister/api/organisations/@objectId', method: 'PUT', responsePath: 'organisation' })
		expect(Object.keys(entry.formProperties)).toEqual(['name', 'description'])
	})

	it('reads Bewerken, and its form and messages read Dutch', () => {
		const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
		const entry = action('organisation-edit')
		for (const key of [entry.label, entry.formTitle, entry.successMessage, entry.errorMessage, 'Name', 'Description']) {
			expect(nl[key]).toBeTruthy()
			expect(nl[key]).not.toBe(key)
		}
		expect(nl[entry.label]).toBe('Bewerken')
	})
})
