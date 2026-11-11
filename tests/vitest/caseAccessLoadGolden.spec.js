// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for CaseAccessTab's `load`
 * (src/views/cases/components/CaseAccessTab.vue): which reads it makes for a
 * reader who may review the access set and for one who may not, what it
 * names as unreadable, the enforcement it shows, and what it hands to the
 * row builder.
 *
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = {
	fetchObjectPermissions: vi.fn(),
	fetchPermissionCatalogue: vi.fn(),
	fetchObjectGrants: vi.fn(),
	fetchRoleGrants: vi.fn(),
	fetchCallerScope: vi.fn(),
	fetchDenyRules: vi.fn(),
	fetchFieldRoleRules: vi.fn(),
	grantRows: vi.fn(),
}
vi.mock('../../src/services/caseAccessApi.js', () => ({
	asOfRows: vi.fn(),
	fetchAccessHistory: vi.fn(),
	fetchCallerScope: (...a) => api.fetchCallerScope(...a),
	fetchDenyRules: (...a) => api.fetchDenyRules(...a),
	fetchFieldRoleRules: (...a) => api.fetchFieldRoleRules(...a),
	fetchObjectGrants: (...a) => api.fetchObjectGrants(...a),
	fetchObjectPermissions: (...a) => api.fetchObjectPermissions(...a),
	fetchPermissionCatalogue: (...a) => api.fetchPermissionCatalogue(...a),
	fetchRoleGrants: (...a) => api.fetchRoleGrants(...a),
	grantRows: (...a) => api.grantRows(...a),
}))
const mockFieldRows = vi.fn()
vi.mock('../../src/utils/fieldRoleRules.js', () => ({
	fieldRoleRows: (...a) => mockFieldRows(...a),
	roleRuleLabels: vi.fn(),
	roleRuleSentence: vi.fn(),
}))

const { default: CaseAccessTab } = await import('../../src/views/cases/components/CaseAccessTab.vue')

const FIELD_RULES = { declared: ['d'], decided: ['x'] }

/**
 * A stand-in for the panel instance, carrying every method.
 *
 * @param {string} objectId The case.
 * @return {object} The instance.
 */
function instance(objectId = 'case-1') {
	return {
		...CaseAccessTab.methods,
		objectId,
		loading: null,
		reviewRefused: null,
		historyReadable: null,
		fieldRows: null,
		catalogue: null,
		enforcement: null,
		unreadable: null,
		rows: null,
	}
}

/**
 * Every fallback source answers.
 *
 * @return {void}
 */
function sourcesAnswer() {
	api.fetchPermissionCatalogue.mockResolvedValue({ permissions: ['read'], denyEnforcement: 'staged' })
	api.fetchObjectGrants.mockResolvedValue(['og'])
	api.fetchRoleGrants.mockResolvedValue(['rg'])
	api.fetchCallerScope.mockResolvedValue({ scope: 1 })
	api.fetchDenyRules.mockResolvedValue({ denyEnforcement: 'off' })
	api.fetchFieldRoleRules.mockResolvedValue(FIELD_RULES)
}

describe('case access load golden', () => {
	beforeEach(() => {
		Object.values(api).forEach((fn) => fn.mockReset())
		mockFieldRows.mockReset()
		mockFieldRows.mockReturnValue(['field row'])
		api.grantRows.mockReturnValue(['row'])
		sourcesAnswer()
	})

	it('reads the access set once and skips the fallback sources for a reviewer', async () => {
		const set = { denyEnforcement: 'on' }
		api.fetchObjectPermissions.mockResolvedValue({ status: 200, set })
		const vm = instance()
		await vm.load()
		expect(api.fetchObjectPermissions).toHaveBeenCalledWith('case-1')
		expect(api.fetchObjectGrants).not.toHaveBeenCalled()
		expect(api.fetchRoleGrants).not.toHaveBeenCalled()
		expect(api.fetchDenyRules).not.toHaveBeenCalled()
		expect(api.fetchFieldRoleRules).toHaveBeenCalledWith('case-1')
		expect(mockFieldRows).toHaveBeenCalledWith(['d'], ['x'])
		expect(vm).toMatchObject({
			loading: false,
			reviewRefused: false,
			historyReadable: true,
			fieldRows: ['field row'],
			catalogue: ['read'],
			enforcement: 'on',
			unreadable: [],
			rows: ['row'],
		})
		expect(api.grantRows).toHaveBeenCalledWith({
			objectPermissions: set,
			objectGrants: null,
			roleGrants: null,
			callerScope: { scope: 1 },
			denyRules: null,
		})
	})

	it('names only the catalogue and the caller scope as unreadable for a reviewer', async () => {
		api.fetchObjectPermissions.mockResolvedValue({ status: 200, set: {} })
		api.fetchPermissionCatalogue.mockResolvedValue(null)
		api.fetchCallerScope.mockResolvedValue(null)
		const vm = instance()
		await vm.load()
		expect(vm.unreadable).toEqual(['the rights it publishes', 'your own rights'])
		expect(vm.catalogue).toEqual([])
		expect(vm.enforcement).toBe('')
	})

	it('assembles the fallback sources when the access set is not readable', async () => {
		api.fetchObjectPermissions.mockResolvedValue({ status: 500, set: null })
		const vm = instance()
		await vm.load()
		expect(api.fetchObjectGrants).toHaveBeenCalledWith('case-1')
		expect(api.fetchRoleGrants).toHaveBeenCalledTimes(1)
		expect(api.fetchDenyRules).toHaveBeenCalledTimes(1)
		expect(vm).toMatchObject({
			reviewRefused: false,
			historyReadable: false,
			enforcement: 'staged',
			unreadable: [],
		})
		expect(api.grantRows).toHaveBeenCalledWith({
			objectPermissions: null,
			objectGrants: ['og'],
			roleGrants: ['rg'],
			callerScope: { scope: 1 },
			denyRules: { denyEnforcement: 'off' },
		})
	})

	it('names every fallback source that failed, in order', async () => {
		api.fetchObjectPermissions.mockResolvedValue({ status: 500, set: null })
		api.fetchPermissionCatalogue.mockResolvedValue(null)
		api.fetchObjectGrants.mockResolvedValue(null)
		api.fetchRoleGrants.mockResolvedValue(null)
		api.fetchCallerScope.mockResolvedValue(null)
		api.fetchDenyRules.mockResolvedValue(null)
		const vm = instance()
		await vm.load()
		expect(vm.unreadable).toEqual([
			'the rights it publishes',
			'the shares on this case',
			'the roles',
			'the refusals',
			'your own rights',
		])
		expect(vm.enforcement).toBe('')
	})

	it('takes the enforcement from the refusals when nothing else carries it', async () => {
		api.fetchObjectPermissions.mockResolvedValue({ status: 500, set: null })
		api.fetchPermissionCatalogue.mockResolvedValue({ permissions: ['p'] })
		const vm = instance()
		await vm.load()
		expect(vm.enforcement).toBe('off')
	})

	it('refuses review on a 403: names nothing unreadable, still shows the field rules', async () => {
		api.fetchObjectPermissions.mockResolvedValue({ status: 403, set: null })
		api.fetchRoleGrants.mockResolvedValue(null)
		const vm = instance()
		await vm.load()
		expect(vm.reviewRefused).toBe(true)
		expect(vm.historyReadable).toBe(false)
		expect(vm.unreadable).toEqual([])
		expect(vm.fieldRows).toEqual(['field row'])
		expect(vm.loading).toBe(false)
	})

	it('asks nothing about a case it has no id for, and names the shares as unreadable', async () => {
		const vm = instance('')
		await vm.load()
		expect(api.fetchObjectPermissions).not.toHaveBeenCalled()
		expect(api.fetchObjectGrants).not.toHaveBeenCalled()
		expect(api.fetchFieldRoleRules).toHaveBeenCalledWith('')
		expect(vm.reviewRefused).toBe(false)
		expect(vm.historyReadable).toBe(false)
		expect(vm.unreadable).toEqual(['the shares on this case'])
	})
})
