// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two settings sections as drawn. ZGW mapping: every stored resource is a row
 * with its readable name and enabled / disabled / not-configured state; Edit
 * opens the dialog on that mapping, a successful save closes it and confirms
 * for three seconds, a refused save keeps it open, and Reset asks the store.
 * Substitution: only the signed-in user's own substitutions are listed, an
 * active one can be revoked, a failed load or revoke leaves an empty or
 * unchanged list, and a created substitution closes the form and reloads.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const zgwStore = vi.hoisted(() => ({
	mappings: {},
	fetchMappings: vi.fn(),
	saveMapping: vi.fn(),
	resetMapping: vi.fn(),
}))
const substitutionApi = vi.hoisted(() => ({ listSubstitutions: vi.fn(), revokeSubstitution: vi.fn() }))
const auth = vi.hoisted(() => ({ user: { uid: 'anna' } }))

vi.mock('../../src/store/modules/zgwMapping.js', () => ({ useZgwMappingStore: () => zgwStore }))
vi.mock('../../src/services/substitutionApi.js', () => substitutionApi)
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => auth.user }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', props: ['type'], template: '<button><slot /></button>' },
	NcEmptyContent: { name: 'NcEmptyContent', props: ['name', 'description'], template: '<div class="empty">{{ name }}</div>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<i class="loading" />' },
}))
vi.mock('vue-material-design-icons/AccountSwitch.vue', () => ({ default: { template: '<i />' } }))
vi.mock('../../src/dialogs/ZgwMappingDialog.vue', () => ({
	default: { name: 'ZgwMappingDialog', props: ['open', 'resourceKey', 'mapping'], emits: ['save', 'close'], template: '<div />' },
}))
vi.mock('../../src/modals/SubstitutionFormModal.vue', () => ({
	default: { name: 'SubstitutionFormModal', emits: ['created', 'close'], template: '<div class="form" />' },
}))

const { default: ZgwMappingSettings } = await import('../../src/views/settings/ZgwMappingSettings.vue')
const { default: SubstitutionSettings } = await import('../../src/views/settings/SubstitutionSettings.vue')

function button(wrapper, text) {
	return wrapper.findAll('button').filter((b) => b.text() === text)
}

describe('ZgwMappingSettings', () => {
	beforeEach(() => {
		zgwStore.mappings = { zaak: { enabled: true }, roltype: { enabled: false }, custom: null }
		zgwStore.fetchMappings.mockReset().mockResolvedValue()
		zgwStore.saveMapping.mockReset()
		zgwStore.resetMapping.mockReset().mockResolvedValue()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('fetches the mappings and draws a row per resource with its state', async () => {
		const wrapper = mount(ZgwMappingSettings)
		await flushPromises()

		expect(zgwStore.fetchMappings).toHaveBeenCalledTimes(1)
		const rows = wrapper.findAll('tbody tr').map((tr) => tr.findAll('td').slice(0, 2).map((td) => td.text()))
		expect(rows).toEqual([['Case', 'Enabled'], ['Role type', 'Disabled'], ['custom', 'Not configured']])
		expect(wrapper.findComponent({ name: 'ZgwMappingDialog' }).props()).toEqual({ open: false, resourceKey: '', mapping: {} })
	})

	it('opens the dialog on a mapping, and a saved mapping closes it and confirms for three seconds', async () => {
		vi.useFakeTimers()
		zgwStore.saveMapping.mockResolvedValue({ enabled: true })
		const wrapper = mount(ZgwMappingSettings)
		await flushPromises()

		await button(wrapper, 'Edit')[1].trigger('click')
		const dialog = wrapper.findComponent({ name: 'ZgwMappingDialog' })
		expect(dialog.props()).toEqual({ open: true, resourceKey: 'roltype', mapping: { enabled: false } })

		dialog.vm.$emit('save', { enabled: true, propertyMapping: {} })
		await flushPromises()
		expect(zgwStore.saveMapping).toHaveBeenCalledWith('roltype', { enabled: true, propertyMapping: {} })
		expect(wrapper.findComponent({ name: 'ZgwMappingDialog' }).props('open')).toBe(false)
		expect(wrapper.find('.success-message').text()).toBe('Mapping saved successfully')

		vi.advanceTimersByTime(3000)
		await flushPromises()
		expect(wrapper.find('.success-message').exists()).toBe(false)
	})

	it('keeps the dialog open when the save is refused, and closes it on close', async () => {
		zgwStore.saveMapping.mockResolvedValue(null)
		const wrapper = mount(ZgwMappingSettings)
		await flushPromises()

		await button(wrapper, 'Edit')[2].trigger('click')
		expect(wrapper.findComponent({ name: 'ZgwMappingDialog' }).props('mapping')).toEqual({})
		wrapper.findComponent({ name: 'ZgwMappingDialog' }).vm.$emit('save', {})
		await flushPromises()
		expect(wrapper.findComponent({ name: 'ZgwMappingDialog' }).props('open')).toBe(true)
		expect(wrapper.find('.success-message').exists()).toBe(false)

		wrapper.findComponent({ name: 'ZgwMappingDialog' }).vm.$emit('close')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'ZgwMappingDialog' }).props('open')).toBe(false)
	})

	it('resets a mapping and confirms', async () => {
		vi.useFakeTimers()
		const wrapper = mount(ZgwMappingSettings)
		await flushPromises()

		await button(wrapper, 'Reset')[0].trigger('click')
		await flushPromises()

		expect(zgwStore.resetMapping).toHaveBeenCalledWith('zaak')
		expect(wrapper.find('.success-message').exists()).toBe(true)
		vi.advanceTimersByTime(3000)
		await flushPromises()
		expect(wrapper.find('.success-message').exists()).toBe(false)
	})
})

describe('SubstitutionSettings', () => {
	const ROWS = [
		{ id: 's1', absentee: 'anna', substitute: 'bram', startDate: '2026-11-01', endDate: '2026-11-14', scope: 'all', reason: 'leave', status: 'active' },
		{ id: 's2', absentee: 'anna', substitute: 'cees', startDate: '2026-10-01', endDate: '2026-10-05', scope: 'cases', reason: 'ill', status: 'expired' },
		{ id: 's3', absentee: 'dirk', substitute: 'anna', startDate: '2026-11-01', endDate: '2026-11-30', scope: 'all', reason: 'leave', status: 'active' },
	]

	beforeEach(() => {
		auth.user = { uid: 'anna' }
		substitutionApi.listSubstitutions.mockReset().mockResolvedValue(ROWS)
		substitutionApi.revokeSubstitution.mockReset().mockResolvedValue()
	})

	it('lists only the signed-in user\'s own substitutions, with revoke on the active ones', async () => {
		const wrapper = mount(SubstitutionSettings)
		expect(wrapper.find('.loading').exists()).toBe(true)
		await flushPromises()

		const rows = wrapper.findAll('tbody tr')
		expect(rows.map((tr) => tr.findAll('td').slice(0, 5).map((td) => td.text()))).toEqual([
			['bram', '2026-11-01 → 2026-11-14', 'all', 'leave', 'active'],
			['cees', '2026-10-01 → 2026-10-05', 'cases', 'ill', 'expired'],
		])
		expect(rows[0].find('.status--active').exists()).toBe(true)
		expect(button(rows[0], 'Revoke')).toHaveLength(1)
		expect(button(rows[1], 'Revoke')).toHaveLength(0)
	})

	it('revokes an active substitution and reloads', async () => {
		const wrapper = mount(SubstitutionSettings)
		await flushPromises()

		await button(wrapper.findAll('tbody tr')[0], 'Revoke')[0].trigger('click')
		await flushPromises()

		expect(substitutionApi.revokeSubstitution).toHaveBeenCalledWith('s1')
		expect(substitutionApi.listSubstitutions).toHaveBeenCalledTimes(2)
	})

	it('keeps the list when a revoke fails, and shows the empty state when the load fails', async () => {
		const error = vi.spyOn(console, 'error').mockImplementation(() => {})
		substitutionApi.revokeSubstitution.mockRejectedValue(new Error('403'))
		const wrapper = mount(SubstitutionSettings)
		await flushPromises()
		await wrapper.vm.revoke('s1')
		expect(substitutionApi.listSubstitutions).toHaveBeenCalledTimes(1)
		expect(wrapper.findAll('tbody tr')).toHaveLength(2)

		substitutionApi.listSubstitutions.mockRejectedValue(new Error('down'))
		const failed = mount(SubstitutionSettings)
		await flushPromises()
		error.mockRestore()

		expect(failed.vm.substitutions).toEqual([])
		expect(failed.find('.empty').text()).toBe('No substitutions')
	})

	it('shows nothing of anyone else\'s when nobody is signed in', async () => {
		auth.user = null
		const wrapper = mount(SubstitutionSettings)
		await flushPromises()

		expect(wrapper.find('table').exists()).toBe(false)
		expect(wrapper.find('.empty').exists()).toBe(true)
	})

	it('opens the form, and a created substitution closes it and reloads', async () => {
		const wrapper = mount(SubstitutionSettings)
		await flushPromises()

		await button(wrapper, 'Register substitution')[0].trigger('click')
		const form = wrapper.findComponent({ name: 'SubstitutionFormModal' })
		expect(form.exists()).toBe(true)
		form.vm.$emit('created')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'SubstitutionFormModal' }).exists()).toBe(false)
		expect(substitutionApi.listSubstitutions).toHaveBeenCalledTimes(2)

		await button(wrapper, 'Register substitution')[0].trigger('click')
		wrapper.findComponent({ name: 'SubstitutionFormModal' }).vm.$emit('close')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'SubstitutionFormModal' }).exists()).toBe(false)
	})
})
