// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Characterisation tests for `initializeStores` (src/store/store.js): which
 * object types it registers, in which order, with which schema, for a full
 * config, a bare config, and no config at all.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const registered = []
let settings = null

vi.mock('../../src/store/modules/settings.js', () => ({
	useSettingsStore: () => ({ fetchSettings: async () => settings }),
}))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		registerObjectType: (...args) => registered.push(args),
	}),
}))

const { initializeStores } = await import('../../src/store/store.js')

const FULL = {
	register: 'r1',
	case_schema: 's-case',
	status_schema: 's-status',
	role_schema: 's-role',
	result_schema: 's-result',
	decision_schema: 's-decision',
	case_type_schema: 's-caseType',
	status_type_schema: 's-statusType',
	result_type_schema: 's-resultType',
	role_type_schema: 's-roleType',
	property_definition_schema: 's-propertyDefinition',
	document_type_schema: 's-documentType',
	email_template_schema: 's-emailTemplate',
	decision_type_schema: 's-decisionType',
	map_layer_schema: 's-mapLayer',
	objection_schema: 's-objection',
	hearing_session_schema: 's-hearingSession',
	advisory_report_schema: 's-advisoryReport',
	appeal_decision_schema: 's-appealDecision',
	workflow_template_schema: 's-workflowTemplate',
	case_document_schema: 's-caseDocument',
	brp_person_schema: 's-brpPerson',
	kvk_company_schema: 's-kvkCompany',
}

describe('initializeStores registrations', () => {
	beforeEach(() => {
		registered.length = 0
	})

	it('registers every configured type in order with its schema', async () => {
		settings = FULL
		const stores = await initializeStores()
		expect(Object.keys(stores)).toEqual(['settingsStore', 'objectStore'])
		expect(registered).toEqual([
			['inspectionChecklistTemplate', 'inspectionChecklistTemplate', 'r1'],
			['case', 's-case', 'r1'],
			['status', 's-status', 'r1'],
			['role', 's-role', 'r1'],
			['result', 's-result', 'r1'],
			['decision', 's-decision', 'r1'],
			['caseType', 's-caseType', 'r1'],
			['statusType', 's-statusType', 'r1'],
			['resultType', 's-resultType', 'r1'],
			['roleType', 's-roleType', 'r1'],
			['propertyDefinition', 's-propertyDefinition', 'r1'],
			['documentType', 's-documentType', 'r1'],
			['emailTemplate', 's-emailTemplate', 'r1'],
			['decisionType', 's-decisionType', 'r1'],
			['mapLayer', 's-mapLayer', 'r1'],
			['objection', 's-objection', 'r1'],
			['hearingSession', 's-hearingSession', 'r1'],
			['advisoryReport', 's-advisoryReport', 'r1'],
			['appealDecision', 's-appealDecision', 'r1'],
			['workflowTemplate', 's-workflowTemplate', 'r1'],
			['caseDocument', 's-caseDocument', 'r1'],
			['brpPerson', 's-brpPerson', 'r1'],
			['kvkCompany', 's-kvkCompany', 'r1'],
		])
	})

	it('registers only the slug-fallback types when no schema is configured', async () => {
		settings = { register: 'r2', case_schema: '', role_type_schema: 0 }
		await initializeStores()
		expect(registered).toEqual([
			['inspectionChecklistTemplate', 'inspectionChecklistTemplate', 'r2'],
			['caseType', 'caseType', 'r2'],
			['statusType', 'statusType', 'r2'],
			['resultType', 'resultType', 'r2'],
			['emailTemplate', 'emailTemplate', 'r2'],
			['caseDocument', 'caseDocument', 'r2'],
			['brpPerson', 'brpPerson', 'r2'],
			['kvkCompany', 'kvkCompany', 'r2'],
		])
	})

	it.each([
		['no config', null],
		['a config without a register', { ...FULL, register: '' }],
	])('registers nothing for %s', async (_label, config) => {
		settings = config
		const stores = await initializeStores()
		expect(registered).toEqual([])
		expect(stores.settingsStore).toBeDefined()
		expect(stores.objectStore).toBeDefined()
	})
})
