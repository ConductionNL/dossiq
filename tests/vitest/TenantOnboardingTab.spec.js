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
})
