/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An organisation can be suspended, resumed and ended from its page, through
 * OpenRegister's organisation lifecycle (board DqTenant).
 *
 * @spec openspec/specs/organisation-header-actions/spec.md#requirement-an-organisation-can-be-suspended-resumed-and-ended-from-its-page
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

	it('shows Suspend and Resume in the header and End under More, confirmed', () => {
		expect(config.quickActions).toEqual(['organisation-suspend', 'organisation-resume', 'organisation-edit'])
		expect(action('organisation-end').confirm).toBe(true)
		expect(action('organisation-suspend').confirm).toBe(true)
	})
})

/**
 * @spec openspec/specs/organisation-edit/spec.md#requirement-an-organisation-is-edited-from-its-page
 */
describe('organisation edit', () => {
	it('edits the name and description through OpenRegister\'s organisation endpoint', () => {
		const edit = action('organisation-edit')
		expect(edit.type).toBe('open-form')
		expect(edit.endpoint).toEqual({ url: '/apps/openregister/api/organisations/@objectId', method: 'PATCH', responsePath: 'organisation' })
		expect(Object.keys(edit.formProperties)).toEqual(['name', 'description'])
	})
})

/**
 * @spec openspec/specs/organisation-history-tab/spec.md#requirement-an-organisation-shows-its-history
 */
describe('organisation history', () => {
	const tabs = fragment.pages.find((entry) => entry.id === 'EnvironmentOrganisationDetail').config.widgets.find((widget) => widget.id === 'organisation-panels').content.tabs
	const history = fragment.pages.find((entry) => entry.id === 'EnvironmentOrganisationDetail').config.widgets.find((widget) => widget.id === 'organisation-history')

	it('has a History tab after Billing', () => {
		expect(tabs.map((tab) => tab.label)).toEqual(['Overview', 'Users', 'Mandates', 'Billing', 'Record history'])
		expect(JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations[tabs[4].label]).toBe('Historie')
	})

	it('reads the organisation\'s lifecycle entries from OpenRegister\'s audit trail', () => {
		expect(history.content.endpointSource.url).toContain('/apps/openregister/api/audit-trails?object_uuid=@objectId')
		expect(history.content.endpointSource.url).toContain('action=organisation.status_changed,organisation.updated')
		expect(history.content.endpointSource.responsePath).toBe('results')
	})
})
