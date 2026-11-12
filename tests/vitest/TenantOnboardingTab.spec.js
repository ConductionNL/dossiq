// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The onboarding section lists OpenRegister's organisations, not dossiq's
 * retired tenant store (tenancy-onto-openregister-organisation 6.10). The
 * organisation's uuid is the tenant id every onboarding route takes.
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockGet = vi.fn()
const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({ name, render: () => null })
	return {
		NcButton: stub('NcButton'),
		NcEmptyContent: stub('NcEmptyContent'),
		NcLoadingIcon: stub('NcLoadingIcon'),
		NcNoteCard: stub('NcNoteCard'),
		NcSelect: stub('NcSelect'),
	}
})

const { default: TenantOnboardingTab } =
	await import('../../src/views/settings/tabs/TenantOnboardingTab.vue')

describe('TenantOnboardingTab', () => {
	beforeEach(() => {
		mockGet.mockReset()
		mockPost.mockReset()
	})

	it('lists organisations from openregister', async () => {
		mockGet.mockResolvedValue({
			data: {
				total: 2,
				active: null,
				results: [
					{
						uuid: 'org-1',
						name: 'Gemeente Zuiddrecht',
						slug: 'zuiddrecht',
					},
					{ uuid: 'org-2', name: '', slug: 'westdorp' },
				],
			},
		})

		const wrapper = shallowMount(TenantOnboardingTab)
		await flushPromises()

		expect(mockGet).toHaveBeenCalledTimes(1)
		expect(mockGet.mock.calls[0][0]).toBe('/apps/openregister/api/organisations')
		expect(
			mockGet.mock.calls.some((c) =>
				String(c[0]).includes('/api/saas/tenants'),
			),
		).toBe(false)
		expect(wrapper.vm.tenantOptions).toEqual([
			{ id: 'org-1', label: 'Gemeente Zuiddrecht' },
			{ id: 'org-2', label: 'westdorp' },
		])
	})

	it('counts a completed step as done', async () => {
		mockGet.mockResolvedValue({ data: { results: [] } })
		const wrapper = shallowMount(TenantOnboardingTab)
		await flushPromises()

		await wrapper.setData({
			progress: {
				steps: [
					{ step: 'contract', status: 'completed' },
					{ step: 'branding', status: 'pending' },
				],
			},
		})

		expect(wrapper.vm.completedSteps).toBe(1)
	})

	/**
	 * Mount with no organisations and select one tenant without loading it.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function mountWithTenant() {
		mockGet.mockResolvedValueOnce({ data: { results: [] } })
		const wrapper = shallowMount(TenantOnboardingTab)
		await flushPromises()
		await wrapper.setData({ tenantId: 'org 1' })
		mockGet.mockReset()
		return wrapper
	}

	const BASE = '/apps/dossiq/api/saas/tenants/org%201/onboarding/'

	it('reads a bare list of organisations and keeps only those with a uuid', async () => {
		mockGet.mockResolvedValue({
			data: [{ uuid: 'org-1', slug: 'oost' }, { name: 'no id' }, null, { uuid: 'org-3' }],
		})
		const wrapper = shallowMount(TenantOnboardingTab)
		await flushPromises()

		expect(wrapper.vm.tenantOptions).toEqual([
			{ id: 'org-1', label: 'oost' },
			{ id: 'org-3', label: 'org-3' },
		])
		expect(wrapper.vm.loading).toBe(false)

		mockGet.mockResolvedValue({ data: {} })
		await wrapper.vm.loadTenants()
		expect(wrapper.vm.tenantOptions).toEqual([])
	})

	it.each([
		[{ response: { data: { message: 'Denied' } }, message: 'x' }, 'Denied'],
		[{ message: 'Network down' }, 'Network down'],
		[{}, 'Failed to load tenants'],
	])('names why the tenants could not be read (%j)', async (error, text) => {
		mockGet.mockRejectedValue(error)
		const wrapper = shallowMount(TenantOnboardingTab)
		await flushPromises()

		expect(wrapper.vm.error).toBe(text)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('initialises a tenant whose progress does not exist yet', async () => {
		const wrapper = await mountWithTenant()
		mockGet
			.mockRejectedValueOnce({ response: { status: 404 } })
			.mockResolvedValueOnce({ data: { steps: [] } })
		mockPost.mockResolvedValue({})

		await wrapper.vm.loadProgress()

		expect(mockGet.mock.calls.map((c) => c[0])).toEqual([BASE + 'progress', BASE + 'progress'])
		expect(mockPost.mock.calls.map((c) => c[0])).toEqual([BASE + 'initialise'])
		expect(wrapper.vm.progress).toEqual({ steps: [] })
		expect(wrapper.vm.error).toBeNull()
		expect(wrapper.vm.loading).toBe(false)
	})

	it('names a failed progress read and a failed initialise', async () => {
		const wrapper = await mountWithTenant()
		mockGet.mockRejectedValueOnce({ response: { status: 500 } })
		await wrapper.vm.loadProgress()
		expect(wrapper.vm.error).toBe('Failed to load progress')

		mockGet.mockRejectedValueOnce({ response: { status: 404 } })
		mockPost.mockRejectedValueOnce({ message: 'Init down' })
		await wrapper.vm.loadProgress()
		expect(wrapper.vm.error).toBe('Init down')

		mockGet.mockResolvedValueOnce({ data: '' })
		await wrapper.vm.loadProgress()
		expect(wrapper.vm.progress).toBeNull()
		expect(wrapper.vm.error).toBeNull()
	})

	it('reads no progress without a tenant', async () => {
		mockGet.mockResolvedValueOnce({ data: { results: [] } })
		const wrapper = shallowMount(TenantOnboardingTab)
		await flushPromises()
		mockGet.mockReset()

		await wrapper.vm.loadProgress()
		expect(mockGet).not.toHaveBeenCalled()
	})

	it('marks a step complete and reloads, or names the failure', async () => {
		const wrapper = await mountWithTenant()
		mockPost.mockResolvedValueOnce({})
		mockGet.mockResolvedValueOnce({ data: { steps: [] } })
		await wrapper.vm.markComplete('a/b')
		expect(mockPost.mock.calls[0][0]).toBe(BASE + 'a%2Fb/complete')
		expect(mockGet.mock.calls[0][0]).toBe(BASE + 'progress')
		expect(wrapper.vm.markingStep).toBe('')

		mockPost.mockRejectedValueOnce({})
		await wrapper.vm.markComplete('contract')
		expect(wrapper.vm.error).toBe('Failed to mark step complete')
		expect(wrapper.vm.markingStep).toBe('')
	})

	it.each([
		[{ goLive: { ready: true, missing: [] } }, { ready: true, missing: [] }],
		[{ readiness: { ready: false, missing: ['x'] } }, { ready: false, missing: ['x'] }],
		[null, { ready: false, missing: ['Open onboarding steps'] }],
	])('reads the go-live answer %j', async (data, result) => {
		const wrapper = await mountWithTenant()
		await wrapper.setData({ progress: { steps: [{ status: 'completed' }, { status: 'open' }] } })
		mockGet.mockResolvedValueOnce({ data })
		await wrapper.vm.checkGoLive()

		expect(mockGet.mock.calls[0]).toEqual([BASE + 'progress', { params: { check: 'go-live' } }])
		expect(wrapper.vm.goLiveResult).toEqual(result)
		expect(wrapper.vm.checkingGoLive).toBe(false)
	})

	it('derives a ready go-live when every step is done, and names a failed check', async () => {
		const wrapper = await mountWithTenant()
		await wrapper.setData({ progress: { steps: [{ status: 'completed' }] } })
		mockGet.mockResolvedValueOnce({ data: {} })
		await wrapper.vm.checkGoLive()
		expect(wrapper.vm.goLiveResult).toEqual({ ready: true, missing: [] })

		mockGet.mockRejectedValueOnce({ message: 'Timeout' })
		await wrapper.vm.checkGoLive()
		expect(wrapper.vm.error).toBe('Timeout')
		expect(wrapper.vm.checkingGoLive).toBe(false)
	})

	it('activates, reloads and checks go-live, or names the failure', async () => {
		const wrapper = await mountWithTenant()
		mockPost.mockResolvedValueOnce({})
		mockGet.mockResolvedValue({ data: { steps: [] } })
		await wrapper.vm.activate()
		expect(mockPost.mock.calls[0][0]).toBe(BASE + 'activate')
		expect(mockGet.mock.calls.map((c) => c[1])).toEqual([undefined, { params: { check: 'go-live' } }])
		expect(wrapper.vm.activating).toBe(false)

		mockPost.mockRejectedValueOnce({})
		await wrapper.vm.activate()
		expect(wrapper.vm.error).toBe('Activate failed')
		expect(wrapper.vm.activating).toBe(false)
	})
})

