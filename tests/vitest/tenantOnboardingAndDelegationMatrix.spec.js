// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tenant onboarding tab (tenants, progress, initialise on first visit,
 * steps, go-live check, activation) and the delegation matrix tab (the three
 * lists per sub tab, the mandate editor, import), mounted with axios doubled.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const stub = (name) => ({ name, render: () => null })
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcEmptyContent: stub('NcEmptyContent'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
	NcSelect: stub('NcSelect'),
}))
vi.mock('../../src/modals/MandaatEditor.vue', () => ({ default: stub('MandaatEditor') }))
vi.mock('../../src/views/settings/components/DelegationImportPanel.vue', () => ({ default: stub('DelegationImportPanel') }))
vi.mock('../../src/views/settings/components/DelegationMatrixTable.vue', () => ({ default: stub('DelegationMatrixTable') }))
vi.mock('../../src/views/settings/components/RoleAssignmentsTable.vue', () => ({ default: stub('RoleAssignmentsTable') }))
vi.mock('../../src/views/settings/components/RoleTreeManager.vue', () => ({ default: stub('RoleTreeManager') }))

const { default: TenantOnboardingTab } = await import('../../src/views/settings/tabs/TenantOnboardingTab.vue')
const { default: DelegationMatrixTab } = await import('../../src/views/settings/tabs/DelegationMatrixTab.vue')

const T = '/index.php/apps/dossiq/api/saas/tenants/t%201/onboarding'

/**
 * Mount a component and let it load.
 *
 * @param {object} component The component.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component) {
	const w = shallowMount(component)
	await flushPromises()
	return w
}

beforeEach(() => {
	for (const verb of ['get', 'post', 'patch']) {
		axios[verb].mockReset()
	}
})

describe('TenantOnboardingTab', () => {
	const progress = { steps: [{ step: 'branding', status: 'completed' }, { step: 'welcome_email', status: 'open' }] }

	it('lists the tenants with a uuid by name', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [{ uuid: 't 1', name: 'Gemeente' }, { uuid: 'u2', slug: 'slug' }, { name: 'no uuid' }, null] } })
		const w = await mountIt(TenantOnboardingTab)
		expect(axios.get.mock.calls[0][0]).toBe('/index.php/apps/openregister/api/organisations')
		expect(w.vm.tenantOptions).toEqual([{ id: 't 1', label: 'Gemeente' }, { id: 'u2', label: 'slug' }])
		expect([w.vm.steps, w.vm.totalSteps, w.vm.progressPercent, w.vm.selectedTenant]).toEqual([[], 7, 0, null])
	})

	it('loads the progress of the chosen tenant and counts the completed steps', async () => {
		axios.get.mockResolvedValueOnce({ data: [{ uuid: 't 1' }] }).mockResolvedValueOnce({ data: progress })
		const w = await mountIt(TenantOnboardingTab)
		w.vm.onTenantChange({ id: 't 1' })
		await flushPromises()
		expect(axios.get.mock.calls[1][0]).toBe(`${T}/progress`)
		expect([w.vm.selectedTenant.id, w.vm.totalSteps, w.vm.completedSteps, w.vm.progressPercent]).toEqual(['t 1', 2, 1, 50])
		expect([w.vm.stepLabel('branding'), w.vm.stepLabel('custom')]).toEqual(['Tenant branding', 'custom'])
		w.vm.onTenantChange(null)
		expect([w.vm.tenantId, w.vm.progress]).toEqual(['', null])
		await w.vm.loadProgress()
		expect(axios.get).toHaveBeenCalledTimes(2)
	})

	it('initialises the onboarding of a tenant that has none yet', async () => {
		axios.get.mockResolvedValueOnce({ data: [] }).mockRejectedValueOnce({ response: { status: 404 } }).mockResolvedValueOnce({ data: { tasks: [{ status: 'open' }] } })
		const w = await mountIt(TenantOnboardingTab)
		w.vm.onTenantChange({ id: 't 1' })
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith(`${T}/initialise`)
		expect([w.vm.steps, w.vm.error]).toEqual([[{ status: 'open' }], null])
	})

	it('reports failures to load, initialise, mark and check', async () => {
		axios.get.mockRejectedValueOnce({ response: { data: { message: 'No access' } } })
		const w = await mountIt(TenantOnboardingTab)
		expect(w.vm.error).toBe('No access')
		w.vm.tenantId = 't 1'
		axios.get.mockRejectedValueOnce({ response: { status: 500 }, message: 'Server down' })
		await w.vm.loadProgress()
		expect(w.vm.error).toBe('Server down')
		axios.post.mockRejectedValueOnce({})
		await w.vm.initialise()
		expect(w.vm.error).toBe('Failed to initialise')
		axios.post.mockRejectedValueOnce({})
		await w.vm.markComplete('branding')
		expect([w.vm.error, w.vm.markingStep]).toEqual(['Failed to mark step complete', ''])
		axios.get.mockRejectedValueOnce({})
		await w.vm.checkGoLive()
		expect([w.vm.error, w.vm.checkingGoLive]).toEqual(['Go-live check failed', false])
		axios.post.mockRejectedValueOnce({})
		await w.vm.activate()
		expect([w.vm.error, w.vm.activating]).toEqual(['Activate failed', false])
	})

	it('marks a step complete and reloads the progress', async () => {
		axios.get.mockResolvedValueOnce({ data: [] })
		const w = await mountIt(TenantOnboardingTab)
		w.vm.tenantId = 't 1'
		axios.get.mockResolvedValueOnce({ data: progress })
		await w.vm.markComplete('welcome email')
		expect(axios.post).toHaveBeenCalledWith(`${T}/welcome%20email/complete`)
		expect(w.vm.completedSteps).toBe(1)
	})

	it('reads the go-live verdict from the server, or works it out from the steps', async () => {
		axios.get.mockResolvedValueOnce({ data: [] })
		const w = await mountIt(TenantOnboardingTab)
		w.vm.tenantId = 't 1'
		axios.get.mockResolvedValueOnce({ data: { goLive: { ready: false, missing: ['contract'] } } })
		await w.vm.checkGoLive()
		expect(axios.get.mock.calls[1][1]).toEqual({ params: { check: 'go-live' } })
		expect(w.vm.goLiveResult).toEqual({ ready: false, missing: ['contract'] })
		w.vm.progress = progress
		axios.get.mockResolvedValueOnce({ data: {} })
		await w.vm.checkGoLive()
		expect(w.vm.goLiveResult).toEqual({ ready: false, missing: ['Open onboarding steps'] })
		w.vm.progress = { steps: [{ status: 'completed' }] }
		axios.get.mockResolvedValueOnce({ data: { readiness: null } })
		await w.vm.checkGoLive()
		expect(w.vm.goLiveResult).toEqual({ ready: true, missing: [] })
	})

	it('activates the tenant, then reloads and checks again', async () => {
		axios.get.mockResolvedValue({ data: { steps: [{ status: 'completed' }], goLive: { ready: true, missing: [] } } })
		const w = await mountIt(TenantOnboardingTab)
		w.vm.tenantId = 't 1'
		await w.vm.activate()
		expect(axios.post).toHaveBeenCalledWith(`${T}/activate`)
		expect(w.vm.goLiveResult).toEqual({ ready: true, missing: [] })
	})
})

describe('DelegationMatrixTab', () => {
	const M = '/index.php/apps/dossiq/api/mandate'

	it('loads the decisions on open and each list when its tab is chosen', async () => {
		axios.get.mockImplementation(async (url) => {
			if (url.endsWith('/besluiten')) return { data: [{ id: 'b' }] }
			if (url.endsWith('/rollen')) return { data: { results: [{ id: 'r1', name: 'Head' }, { id: 'r2', label: 'L' }, { id: 'r3' }] } }
			return { data: { results: [{ id: 'a' }] } }
		})
		const w = await mountIt(DelegationMatrixTab)
		expect(w.vm.matrices).toEqual([{ id: 'b' }])
		expect(w.vm.activeOption.id).toBe('besluiten')
		w.vm.active = 'rollen'
		await flushPromises()
		expect(w.vm.roleOptions).toEqual([{ id: 'r1', label: 'Head' }, { id: 'r2', label: 'L' }, { id: 'r3', label: 'r3' }])
		w.vm.active = 'toewijzingen'
		await flushPromises()
		expect(w.vm.assignments).toEqual([{ id: 'a' }])
		w.vm.openImport()
		await flushPromises()
		expect([w.vm.active, w.vm.activeOption.id]).toEqual(['import', 'import'])
		expect(axios.get.mock.calls.map((c) => c[0])).toEqual([`${M}/besluiten`, `${M}/rollen`, `${M}/toewijzingen`])
		w.vm.active = 'other'
		expect(w.vm.activeOption.id).toBe('besluiten')
	})

	it('keeps empty lists when a load fails', async () => {
		axios.get.mockRejectedValue(new Error('x'))
		const w = await mountIt(DelegationMatrixTab)
		await w.vm.loadRoles()
		await w.vm.loadAssignments()
		expect([w.vm.matrices, w.vm.roles, w.vm.assignments, w.vm.loading]).toEqual([[], [], [], false])
	})

	it('creates or updates a mandate, then closes the editor and reloads', async () => {
		axios.get.mockResolvedValue({ data: [] })
		const w = await mountIt(DelegationMatrixTab)
		w.vm.openEditor(null)
		expect(w.vm.editorOpen).toBe(true)
		await w.vm.onMandaatSave({ a: 1 })
		expect(axios.post).toHaveBeenCalledWith(`${M}/mandaten`, { a: 1 })
		expect([w.vm.editorOpen, w.vm.editingMandaat]).toEqual([false, null])
		w.vm.openEditor({ id: 'm 1' })
		await w.vm.onMandaatSave({ b: 2 })
		expect(axios.patch).toHaveBeenCalledWith(`${M}/mandaten/m%201`, { b: 2 })
		w.vm.openEditor({ id: 'm2' })
		axios.patch.mockRejectedValueOnce(new Error('x'))
		await w.vm.onMandaatSave({})
		expect(w.vm.editorOpen).toBe(true)
		w.vm.closeEditor()
		expect(w.vm.editorOpen).toBe(false)
	})

	it('returns to the decisions after an import', async () => {
		axios.get.mockResolvedValue({ data: [] })
		const w = await mountIt(DelegationMatrixTab)
		w.vm.active = 'import'
		await flushPromises()
		const before = axios.get.mock.calls.length
		w.vm.onImported()
		await flushPromises()
		expect(w.vm.active).toBe('besluiten')
		expect(axios.get.mock.calls.length).toBeGreaterThan(before)
	})
})
