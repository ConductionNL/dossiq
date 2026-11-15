// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case access tab, mounted with the access reads doubled: which sources it
 * reads when the per-case permission set is or is not readable, what it says it
 * could not read, how it words enforcement, the hints per grant row, and the
 * rights as they stood on an earlier date.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({ NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null } }))
const reads = vi.hoisted(() => ({
	fetchAccessHistory: vi.fn(),
	fetchCallerScope: vi.fn(),
	fetchDenyRules: vi.fn(),
	fetchFieldRoleRules: vi.fn(),
	fetchObjectGrants: vi.fn(),
	fetchObjectPermissions: vi.fn(),
	fetchPermissionCatalogue: vi.fn(),
	fetchRoleGrants: vi.fn(),
}))
vi.mock('../../src/services/caseAccessApi.js', async (orig) => ({ ...(await orig()), ...reads }))

const { default: CaseAccessTab } = await import('../../src/views/cases/components/CaseAccessTab.vue')

/**
 * Mount the tab and let it load.
 *
 * @param {object} [props] Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountTab(props = { objectId: 'c1' }) {
	const w = shallowMount(CaseAccessTab, { props })
	await flushPromises()
	return w
}

beforeEach(() => {
	vi.clearAllMocks()
	reads.fetchPermissionCatalogue.mockResolvedValue({ permissions: [{ action: 'read', description: 'Open the case' }], denyEnforcement: 'staged' })
	reads.fetchCallerScope.mockResolvedValue({})
	reads.fetchFieldRoleRules.mockResolvedValue({ declared: [{ field: 'bsn', rule: 'hidden', groups: ['Front'] }], decided: { hidden: ['bsn'] } })
	reads.fetchObjectGrants.mockResolvedValue([])
	reads.fetchRoleGrants.mockResolvedValue([])
	reads.fetchDenyRules.mockResolvedValue({ rules: [] })
})

describe('reading the sources', () => {
	it('reads only the catalogue, caller scope and field rules when the permission set is readable', async () => {
		reads.fetchObjectPermissions.mockResolvedValueOnce({ status: 200, set: { denyEnforcement: 'enforcing' } })
		const w = await mountTab()
		expect(reads.fetchObjectGrants).not.toHaveBeenCalled()
		expect(reads.fetchRoleGrants).not.toHaveBeenCalled()
		expect(reads.fetchDenyRules).not.toHaveBeenCalled()
		expect(reads.fetchFieldRoleRules).toHaveBeenCalledWith('c1')
		expect([w.vm.historyReadable, w.vm.reviewRefused, w.vm.enforcement, w.vm.unreadable, w.vm.loading]).toEqual([true, false, 'enforcing', [], false])
		expect(w.vm.enforcementSentence).toBe('A refusal below is in force now.')
		expect(w.vm.fieldRows).toEqual([{ field: 'bsn', rule: 'hidden', groups: ['Front'], heldBy: [], reason: '', appliesToMe: true }])
		expect(w.vm.describe('read')).toBe('Open the case')
		expect(w.vm.describe('write')).toBe('')
	})

	it('falls back to the shares, roles and refusals, and names what it could not read', async () => {
		reads.fetchObjectPermissions.mockResolvedValueOnce({ status: 500, set: null })
		reads.fetchPermissionCatalogue.mockResolvedValueOnce(null)
		reads.fetchObjectGrants.mockResolvedValueOnce(null)
		reads.fetchRoleGrants.mockResolvedValueOnce(null)
		reads.fetchDenyRules.mockResolvedValueOnce(null)
		reads.fetchCallerScope.mockResolvedValueOnce(null)
		const w = await mountTab()
		expect(reads.fetchObjectGrants).toHaveBeenCalledWith('c1')
		expect(w.vm.historyReadable).toBe(false)
		expect(w.vm.unreadable).toEqual(['the rights it publishes', 'the shares on this case', 'the roles', 'the refusals', 'your own rights'])
		expect(w.vm.enforcementSentence).toBe('Refusals are staged: a rule below is recorded, and removes a right once it is switched on.')
	})

	it('keeps the unreadable list empty when the review itself is refused', async () => {
		reads.fetchObjectPermissions.mockResolvedValueOnce({ status: 403, set: null })
		reads.fetchCallerScope.mockResolvedValueOnce(null)
		reads.fetchDenyRules.mockResolvedValueOnce({ denyEnforcement: 'off' })
		reads.fetchPermissionCatalogue.mockResolvedValueOnce({ permissions: [] })
		const w = await mountTab()
		expect([w.vm.reviewRefused, w.vm.unreadable, w.vm.enforcement]).toEqual([true, [], 'off'])
		expect(w.vm.enforcementSentence).toBe('Refusals are switched off, so no rule below removes a right.')
	})

	it('reads no case-bound permissions without a case', async () => {
		const w = await mountTab({})
		expect(reads.fetchObjectPermissions).not.toHaveBeenCalled()
		expect(reads.fetchObjectGrants).not.toHaveBeenCalled()
		expect(w.vm.historyReadable).toBe(false)
	})
})

describe('an earlier date', () => {
	it('shows the rights as they stood at the end of the chosen day', async () => {
		reads.fetchObjectPermissions.mockResolvedValue({ status: 200, set: {} })
		reads.fetchAccessHistory.mockResolvedValueOnce({ history: [] })
		const w = await mountTab()
		await w.vm.loadMoment()
		expect(reads.fetchAccessHistory).not.toHaveBeenCalled()
		expect(w.vm.asOf).toBeNull()
		w.vm.moment = '2026-03-01'
		await w.vm.loadMoment()
		expect(reads.fetchAccessHistory).toHaveBeenCalledWith('c1', '2026-03-01T23:59:59')
		expect(w.vm.momentLoading).toBe(false)
		expect(w.vm.today).toMatch(/^\d{4}-\d{2}-\d{2}$/)
	})

	it('names who set the rights and who changed them afterwards', async () => {
		reads.fetchObjectPermissions.mockResolvedValue({ status: 200, set: {} })
		const w = await mountTab()
		expect(w.vm.setBySentence).toBe('These rights stood on that date. Nobody is named as setting them.')
		expect(w.vm.changedAfterwardsSentence).toBe('')
		w.vm.asOf = { setBy: 'admin', changedAfterwardsBy: { by: 'ann', at: '2026-04-01' } }
		expect([w.vm.setBySentence, w.vm.changedAfterwardsSentence]).toEqual(['admin set these rights.', 'ann changed them afterwards, on 2026-04-01.'])
		w.vm.asOf = { changedAfterwardsBy: {} }
		expect(w.vm.changedAfterwardsSentence).toBe('Somebody changed them afterwards, on a date the trail does not give.')
	})
})

describe('row hints', () => {
	it('explains a grant through a role, an inherited one, its end and its area', async () => {
		reads.fetchObjectPermissions.mockResolvedValue({ status: 200, set: {} })
		const w = await mountTab()
		expect([w.vm.roleHint({ role: 'Handler' }), w.vm.roleHint({ detail: 'Shared by Ann' }), w.vm.roleHint(null)]).toEqual(['Through the role Handler', 'Shared by Ann', ''])
		expect([w.vm.inheritedHint({ inheritedFrom: 'Z-1' }), w.vm.inheritedHint({})]).toEqual(['Granted on case Z-1, which this one hangs under', ''])
		expect(w.vm.endsHint({ until: '2026-12-31' })).toBe('Written to end on 2026-12-31')
		expect([w.vm.areaHint({ scopedTo: { registers: ['dossiq'], schemas: ['case', ''] } }), w.vm.areaHint({})]).toEqual(['Only in dossiq, case', ''])
		expect(['share', 'staged-deny', 'default-open', 'inherited', 'custom'].map(w.vm.sourceLabel)).toEqual([
			'A share on this case', 'A rule that will refuse', 'No rule, so it is open', 'A grant on a case this one hangs under', 'custom',
		])
		expect(w.vm.sentenceFor({ rule: 'hidden', groups: ['Front'], heldBy: [] })).toBe('Front do not see this field.')
		expect(w.vm.ruleLabels.hidden).toBe('Not shown')
	})
})
