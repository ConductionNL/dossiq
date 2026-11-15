// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The user settings dialog's own sections (order, ids, names, the daily
 * digest form) and the workflow editor's palette (drag payload, keyboard
 * alternative).
 *
 * @spec openspec/specs/personal-settings-surface/spec.md
 */

import { mount, shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => {
	const section = {
		name: 'NcAppSettingsSection',
		props: ['id', 'name'],
		template: '<section :id="id" class="nc-section"><slot name="icon" /><slot /></section>',
	}
	const button = {
		name: 'NcButton',
		props: ['type'],
		emits: ['click'],
		template: '<button class="nc-button" @click="$emit(\'click\', $event)"><slot /></button>',
	}
	return { NcAppSettingsSection: section, NcButton: button }
})
vi.mock('@conduction/nextcloud-vue', () => ({
	CnSettingsFieldsSection: { name: 'CnSettingsFieldsSection', props: ['name', 'url', 'saveMethod', 'bare', 'autoSave', 'saveAll', 'reloadAfterSave', 'fields'], template: '<div class="cn-fields" />' },
}))
vi.mock('../../src/views/settings/NotificationRoutingSettings.vue', () => ({ default: { name: 'NotificationRoutingSettings', template: '<div class="routing" />' } }))
vi.mock('../../src/views/settings/SubstitutionSettings.vue', () => ({ default: { name: 'SubstitutionSettings', template: '<div class="substitution" />' } }))
vi.mock('../../src/views/settings/CaseEmailMatchSettings.vue', () => ({ default: { name: 'CaseEmailMatchSettings', template: '<div class="email-match" />' } }))

const { default: UserSettings } = await import('../../src/views/settings/UserSettings.vue')
const { default: WorkflowPalette } = await import('../../src/views/settings/components/WorkflowPalette.vue')
const { workDigestFields } = await import('../../src/views/settings/adminFields.js')

describe('UserSettings', () => {
	it('shows the four personal sections in board order, each with its own settings screen', () => {
		const wrapper = mount(UserSettings)

		const sections = wrapper.findAllComponents({ name: 'NcAppSettingsSection' })
		expect(sections.map((s) => [s.props('id'), s.props('name')])).toEqual([
			['notification-routing', 'Notices'],
			['work-digest', 'Daily overview'],
			['substitution', 'Cover while I am away'],
			['email-matching', 'Link mail to cases'],
		])
		expect(sections[0].find('.routing').exists()).toBe(true)
		expect(sections[2].find('.substitution').exists()).toBe(true)
		expect(sections[3].find('.email-match').exists()).toBe(true)
		expect(sections.every((s) => s.find('svg, .material-design-icon').exists())).toBe(true)
		expect(sections[0].find('.dossiq-user-settings__intro').text())
			.toBe('Choose per kind of notice where you get it. Your organisation decides which ones are required.')
	})

	it('saves the daily overview as one auto-saved POST of all its fields to the personal queue digest', () => {
		const wrapper = mount(UserSettings)

		const digest = wrapper.findComponent({ name: 'CnSettingsFieldsSection' })
		expect({ ...digest.props() }).toEqual({
			name: 'Daily overview',
			url: '/apps/dossiq/api/personal-queue/digest',
			saveMethod: 'POST',
			bare: true,
			autoSave: true,
			saveAll: true,
			reloadAfterSave: true,
			fields: workDigestFields(globalThis.t),
		})
		expect(digest.props('fields').map((f) => f.key)).toEqual(['enabled', 'hour', expect.any(String), 'nothing-waiting'])
	})
})

describe('WorkflowPalette', () => {
	it('puts the dragged element type on the drag and announces it', async () => {
		const wrapper = shallowMount(WorkflowPalette, { global: { stubs: { NcButton: true } } })
		const dataTransfer = { setData: vi.fn(), effectAllowed: 'none' }

		await wrapper.find('.workflow-palette__item').trigger('dragstart', { dataTransfer })

		expect(dataTransfer.setData).toHaveBeenCalledWith('text/plain', 'status')
		expect(dataTransfer.effectAllowed).toBe('copy')
		expect(wrapper.emitted('drag-start')).toEqual([['status']])
		expect(wrapper.find('.workflow-palette__item').attributes('draggable')).toBe('true')
	})

	it('offers a button that adds a status node without dragging', async () => {
		const wrapper = mount(WorkflowPalette)

		const button = wrapper.find('.nc-button')
		expect(button.text()).toBe('Add status node')
		await button.trigger('click')

		expect(wrapper.emitted('add-status')).toHaveLength(1)
		expect(wrapper.emitted('drag-start')).toBeUndefined()
	})

	it('names the elements and the canvas controls', () => {
		const wrapper = mount(WorkflowPalette)

		expect(wrapper.findAll('.workflow-palette__title').map((h) => h.text())).toEqual(['Elements', 'Controls'])
		expect(wrapper.text()).toContain('Pan: Click and drag on empty canvas')
		expect(wrapper.text()).toContain('Zoom: Scroll wheel')
	})
})
