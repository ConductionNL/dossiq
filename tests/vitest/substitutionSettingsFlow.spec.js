// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The substitution settings list only the caller's own substitutions, revoke
 * one through the API and reload after a revoke or a new registration.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockGet = vi.fn()
const mockPost = vi.fn()
const user = vi.hoisted(() => ({ current: { uid: 'alice' } }))
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => user.current }))
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({ name, render: () => null })
	return { NcButton: stub('NcButton'), NcEmptyContent: stub('NcEmptyContent'), NcLoadingIcon: stub('NcLoadingIcon') }
})
vi.mock('../../src/modals/SubstitutionFormModal.vue', () => ({ default: { name: 'SubstitutionFormModal', render: () => null } }))

const { default: SubstitutionSettings } = await import('../../src/views/settings/SubstitutionSettings.vue')

const rows = [
	{ id: 's1', absentee: 'alice', substitute: 'bob', status: 'active', startDate: '2026-01-01', endDate: '2026-01-31', scope: 'all', reason: 'Leave' },
	{ id: 's2', absentee: 'carol', substitute: 'alice', status: 'active' },
	{ id: 's3', absentee: 'alice', substitute: 'dave', status: 'ended' },
]

describe('SubstitutionSettings', () => {
	beforeEach(() => {
		mockGet.mockReset()
		mockPost.mockReset()
		user.current = { uid: 'alice' }
		vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	it('lists only the substitutions the caller is the absentee of', async () => {
		mockGet.mockResolvedValue({ data: { results: rows } })
		const wrapper = shallowMount(SubstitutionSettings)
		await flushPromises()

		expect(mockGet).toHaveBeenCalledWith('/apps/dossiq/api/substitutions')
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.ownSubstitutions.map((s) => s.id)).toEqual(['s1', 's3'])
		const cells = wrapper.findAll('tbody tr')
		expect(cells).toHaveLength(2)
		expect(cells[0].text()).toContain('bob')
		expect(cells[0].text()).toContain('2026-01-01 → 2026-01-31')
		expect(wrapper.find('.status--ended').exists()).toBe(true)
	})

	it('offers revoke only on an active substitution', async () => {
		mockGet.mockResolvedValue({ data: { results: rows } })
		const wrapper = shallowMount(SubstitutionSettings)
		await flushPromises()

		const revokeButtons = wrapper.findAllComponents({ name: 'NcButton' }).filter((b) => b.attributes('aria-label') === 'Revoke substitution')
		expect(revokeButtons).toHaveLength(1)
	})

	it('shows the empty state without a session user or own rows', async () => {
		user.current = null
		mockGet.mockResolvedValue({ data: { results: rows } })
		const wrapper = shallowMount(SubstitutionSettings)
		await flushPromises()

		expect(wrapper.vm.currentUser).toBe('')
		expect(wrapper.vm.ownSubstitutions).toEqual([])
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).exists()).toBe(true)
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('treats a failed load as an empty list', async () => {
		mockGet.mockRejectedValue(new Error('down'))
		const wrapper = shallowMount(SubstitutionSettings)
		await flushPromises()

		expect(wrapper.vm.substitutions).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
		expect(console.error).toHaveBeenCalledWith('[SubstitutionSettings] load failed', expect.any(Error))
	})

	it('revokes through the API and reloads', async () => {
		mockGet.mockResolvedValueOnce({ data: { results: rows } }).mockResolvedValueOnce({ data: { results: [] } })
		mockPost.mockResolvedValue({ data: {} })
		const wrapper = shallowMount(SubstitutionSettings)
		await flushPromises()

		await wrapper.vm.revoke('s 1')
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/substitutions/s%201/revoke')
		expect(mockGet).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.ownSubstitutions).toEqual([])
	})

	it('keeps the list when a revoke fails', async () => {
		mockGet.mockResolvedValue({ data: { results: rows } })
		mockPost.mockRejectedValue(new Error('refused'))
		const wrapper = shallowMount(SubstitutionSettings)
		await flushPromises()

		await wrapper.vm.revoke('s1')

		expect(mockGet).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.ownSubstitutions).toHaveLength(2)
		expect(console.error).toHaveBeenCalledWith('[SubstitutionSettings] revoke failed', expect.any(Error))
	})

	it('closes the modal and reloads after a registration', async () => {
		mockGet.mockResolvedValue({ data: { results: [] } })
		const wrapper = shallowMount(SubstitutionSettings)
		await flushPromises()
		await wrapper.setData({ showModal: true })
		expect(wrapper.findComponent({ name: 'SubstitutionFormModal' }).exists()).toBe(true)

		wrapper.findComponent({ name: 'SubstitutionFormModal' }).vm.$emit('created')
		await flushPromises()

		expect(wrapper.vm.showModal).toBe(false)
		expect(mockGet).toHaveBeenCalledTimes(2)
	})
})
