// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ZGW mapping table: one row per mapping the server holds, its state,
 * and the edit, save and reset round trips through the store.
 *
 * @spec openspec/specs/admin-settings/spec.md#requirement-a-zgw-mapping-row-names-its-resource
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'

const store = vi.hoisted(() => ({ current: null }))
vi.mock('../../src/store/modules/zgwMapping.js', () => ({ useZgwMappingStore: () => store.current }))
vi.mock('../../src/dialogs/ZgwMappingDialog.vue', () => ({ default: { name: 'ZgwMappingDialog', props: ['open', 'resourceKey', 'mapping'], render: () => null } }))
vi.mock('@nextcloud/vue', () => ({ NcButton: { name: 'NcButton', template: '<button @click="$emit(\'click\')"><slot /></button>' } }))

const { default: ZgwMappingSettings } = await import('../../src/views/settings/ZgwMappingSettings.vue')

describe('ZgwMappingSettings', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		store.current = reactive({
			mappings: {},
			fetchMappings: vi.fn(async () => {
				store.current.mappings = { caseType: { enabled: true, x: 1 }, roltype: { enabled: false } }
			}),
			saveMapping: vi.fn(async () => true),
			resetMapping: vi.fn(async () => undefined),
		})
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('fetches the mappings and draws a row per stored key with its state', async () => {
		const wrapper = shallowMount(ZgwMappingSettings, { global: { renderStubDefaultSlot: true } })
		await flushPromises()

		expect(store.current.fetchMappings).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.resourceKeys).toEqual(['caseType', 'roltype'])
		const rows = wrapper.findAll('tbody tr')
		expect(rows).toHaveLength(2)
		expect(rows[0].find('.cn-status-badge--success').exists()).toBe(true)
		expect(rows[1].find('.cn-status-badge--warning').exists()).toBe(true)
	})

	it('opens the dialog on the edited mapping and closes it after a save', async () => {
		const wrapper = shallowMount(ZgwMappingSettings)
		await flushPromises()

		wrapper.vm.editMapping('caseType')
		await wrapper.vm.$nextTick()
		const dialog = wrapper.findComponent({ name: 'ZgwMappingDialog' })
		expect(dialog.props()).toEqual({ open: true, resourceKey: 'caseType', mapping: { enabled: true, x: 1 } })

		dialog.vm.$emit('save', { enabled: false })
		await flushPromises()

		expect(store.current.saveMapping).toHaveBeenCalledWith('caseType', { enabled: false })
		expect(wrapper.vm.editingKey).toBe(null)
		expect(wrapper.find('.success-message').exists()).toBe(true)
		vi.advanceTimersByTime(3000)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.success-message').exists()).toBe(false)
	})

	it('keeps the dialog open when the store refuses the save', async () => {
		store.current.saveMapping = vi.fn(async () => false)
		const wrapper = shallowMount(ZgwMappingSettings)
		await flushPromises()
		wrapper.vm.editMapping('roltype')

		await wrapper.vm.saveMapping({ enabled: true })

		expect(wrapper.vm.editingKey).toBe('roltype')
		expect(wrapper.vm.saved).toBe(false)
	})

	it('resets a mapping and confirms it', async () => {
		const wrapper = shallowMount(ZgwMappingSettings)
		await flushPromises()

		await wrapper.vm.resetMapping('roltype')

		expect(store.current.resetMapping).toHaveBeenCalledWith('roltype')
		expect(wrapper.vm.saved).toBe(true)
		vi.advanceTimersByTime(3000)
		expect(wrapper.vm.saved).toBe(false)
	})

	it('passes a closed dialog an empty mapping when nothing is edited', async () => {
		const wrapper = shallowMount(ZgwMappingSettings)
		await flushPromises()

		expect(wrapper.findComponent({ name: 'ZgwMappingDialog' }).props()).toEqual({ open: false, resourceKey: '', mapping: {} })
		wrapper.findComponent({ name: 'ZgwMappingDialog' }).vm.$emit('close')
		expect(wrapper.vm.editingKey).toBe(null)
	})
})
