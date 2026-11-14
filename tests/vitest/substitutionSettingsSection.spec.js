// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The personal substitution section: it lists only the reader's own
 * substitutions, revokes one and reloads, and survives a failing read.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'alice' }) }))
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({ name, render: () => null })
	return {
		NcButton: stub('NcButton'),
		NcEmptyContent: stub('NcEmptyContent'),
		NcLoadingIcon: stub('NcLoadingIcon'),
	}
})
vi.mock('../../src/modals/SubstitutionFormModal.vue', () => ({
	default: { name: 'SubstitutionFormModal', render: () => null },
}))

const { default: SubstitutionSettings } = await import('../../src/views/settings/SubstitutionSettings.vue')

const ROWS = [
	{ id: 's-1', absentee: 'alice', substitute: 'bob', status: 'active', startDate: '2026-11-01', endDate: '2026-11-30' },
	{ id: 's-2', absentee: 'carol', substitute: 'alice', status: 'active', startDate: '2026-11-01', endDate: '2026-11-30' },
	{ id: 's-3', absentee: 'alice', substitute: 'dave', status: 'revoked', startDate: '2026-10-01', endDate: '2026-10-10' },
]

/**
 * Mount the section after the first read settles.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mounted() {
	const wrapper = shallowMount(SubstitutionSettings)
	await flushPromises()
	return wrapper
}

describe('the substitution section', () => {
	let consoleError

	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		consoleError.mockRestore()
	})

	it('lists only the substitutions the reader registered for their own absence', async () => {
		axios.get.mockResolvedValue({ data: { results: ROWS } })

		const wrapper = await mounted()

		expect(axios.get.mock.calls[0][0]).toContain('/api/substitutions')
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.ownSubstitutions.map((s) => s.id)).toEqual(['s-1', 's-3'])
		expect(wrapper.findAll('tbody tr')).toHaveLength(2)
	})

	it('shows the empty state when the reader registered none', async () => {
		axios.get.mockResolvedValue({ data: { results: [ROWS[1]] } })

		const wrapper = await mounted()

		expect(wrapper.vm.ownSubstitutions).toEqual([])
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('treats a failing read as no substitutions, and logs it', async () => {
		axios.get.mockRejectedValue(new Error('Network Error'))

		const wrapper = await mounted()

		expect(wrapper.vm.substitutions).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
		expect(consoleError).toHaveBeenCalledWith('[SubstitutionSettings] load failed', expect.any(Error))
	})

	it('revokes a substitution and reads the list again', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: ROWS } })
		axios.get.mockResolvedValueOnce({ data: { results: [ROWS[2]] } })
		axios.post.mockResolvedValue({ data: { id: 's-1', status: 'revoked' } })
		const wrapper = await mounted()

		await wrapper.vm.revoke('s-1')

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post.mock.calls[0][0]).toContain('/api/substitutions/s-1/revoke')
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.ownSubstitutions.map((s) => s.id)).toEqual(['s-3'])
	})

	it('keeps the list when a revoke is refused, and logs it', async () => {
		axios.get.mockResolvedValue({ data: { results: ROWS } })
		axios.post.mockRejectedValue(new Error('Forbidden'))
		const wrapper = await mounted()

		await wrapper.vm.revoke('s-1')

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.ownSubstitutions.map((s) => s.id)).toEqual(['s-1', 's-3'])
		expect(consoleError).toHaveBeenCalledWith('[SubstitutionSettings] revoke failed', expect.any(Error))
	})

	it('closes the form and reloads once a substitution is created', async () => {
		axios.get.mockResolvedValue({ data: { results: [] } })
		const wrapper = await mounted()
		await wrapper.setData({ showModal: true })

		await wrapper.vm.onCreated()

		expect(wrapper.vm.showModal).toBe(false)
		expect(axios.get).toHaveBeenCalledTimes(2)
	})
})
