// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The ZGW mapping section: it loads the mappings once mounted, edits one at a
 * time, and flashes the saved note only when the store accepted the change.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const store = {
	mappings: {},
	fetchMappings: vi.fn(),
	saveMapping: vi.fn(),
	resetMapping: vi.fn(),
}

vi.mock('../../src/store/modules/zgwMapping.js', () => ({ useZgwMappingStore: () => store }))
vi.mock('../../src/dialogs/ZgwMappingDialog.vue', () => ({
	default: { name: 'ZgwMappingDialog', render: () => null },
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: { name: 'NcButton', render: () => null } }))

const { default: ZgwMappingSettings } = await import('../../src/views/settings/ZgwMappingSettings.vue')

/**
 * Mount the section after the first load settles.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mounted() {
	const wrapper = shallowMount(ZgwMappingSettings)
	await flushPromises()
	return wrapper
}

describe('the ZGW mapping section', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		store.mappings = {
			caseType: { enabled: true },
			roltype: { enabled: false },
		}
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('loads the mappings once mounted and lists one row per resource', async () => {
		const wrapper = await mounted()

		expect(store.fetchMappings).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.resourceKeys).toEqual(['caseType', 'roltype'])
		expect(wrapper.vm.resourceName('caseType')).toBe('Case type')
		expect(wrapper.vm.resourceName('somethingNew')).toBe('somethingNew')
	})

	it('saves the mapping being edited, closes it and flashes the saved note for three seconds', async () => {
		vi.useFakeTimers()
		store.saveMapping.mockResolvedValue({ caseType: { enabled: true } })
		const wrapper = await mounted()
		wrapper.vm.editMapping('caseType')

		await wrapper.vm.saveMapping({ enabled: true, sourceId: 'src-1' })

		expect(store.saveMapping).toHaveBeenCalledWith('caseType', { enabled: true, sourceId: 'src-1' })
		expect(wrapper.vm.editingKey).toBeNull()
		expect(wrapper.vm.saved).toBe(true)
		vi.advanceTimersByTime(3000)
		expect(wrapper.vm.saved).toBe(false)
	})

	it('keeps the mapping open when the store refuses the save', async () => {
		store.saveMapping.mockResolvedValue(null)
		const wrapper = await mounted()
		wrapper.vm.editMapping('roltype')

		await wrapper.vm.saveMapping({ enabled: true })

		expect(wrapper.vm.editingKey).toBe('roltype')
		expect(wrapper.vm.saved).toBe(false)
	})

	it('resets a mapping and flashes the saved note', async () => {
		vi.useFakeTimers()
		store.resetMapping.mockResolvedValue(undefined)
		const wrapper = await mounted()

		await wrapper.vm.resetMapping('roltype')

		expect(store.resetMapping).toHaveBeenCalledWith('roltype')
		expect(wrapper.vm.saved).toBe(true)
		vi.advanceTimersByTime(2999)
		expect(wrapper.vm.saved).toBe(true)
		vi.advanceTimersByTime(1)
		expect(wrapper.vm.saved).toBe(false)
	})
})
