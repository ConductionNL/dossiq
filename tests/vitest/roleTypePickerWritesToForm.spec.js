// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Role field of the Add party form writes the picked role to the form.
 *
 * CnFormDialog mounts a registry form-field widget with `value`/`modelValue`
 * and listens for `input` and `update:modelValue` only, so a pick has to
 * leave the picker on one of those.
 *
 * @spec openspec/specs/roles-decisions/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import RoleTypePicker from '../../src/components/case/RoleTypePicker.vue'

vi.mock('../../src/services/roleTypeOptions.js', () => ({
	fetchRoleTypes: vi.fn(async () => [{ id: 'rt-1' }, { id: 'rt-2' }]),
	fetchCaseTypeOf: vi.fn(async () => 'ct-1'),
	offeredRoleTypes: vi.fn(() => [
		{ id: 'rt-1', label: 'Handler' },
		{ id: 'rt-2', label: 'Gemachtigde' },
	]),
}))

const stubs = {
	NcLoadingIcon: true,
	NcNoteCard: true,
	NcSelect: {
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		template: '<div class="nc-select-stub">{{ modelValue && modelValue.label }}</div>',
	},
}

/**
 * Mount the picker as CnFormDialog mounts a form-field widget.
 *
 * @param {string|null} current The value the form holds.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountAsFormField(current = null) {
	const wrapper = mount(RoleTypePicker, {
		props: { value: current, modelValue: current, caseId: 'case-1' },
		global: { stubs },
	})
	await flushPromises()
	return wrapper
}

describe('RoleTypePicker inside CnFormDialog', () => {
	it('writes the picked role uuid back through update:modelValue', async () => {
		const wrapper = await mountAsFormField()

		wrapper.findComponent(stubs.NcSelect).vm.$emit('update:modelValue', { id: 'rt-2', label: 'Gemachtigde' })

		expect(wrapper.emitted('update:modelValue')).toEqual([['rt-2']])
	})

	it('shows the role the form already holds', async () => {
		const wrapper = await mountAsFormField('rt-1')

		expect(wrapper.find('.nc-select-stub').text()).toBe('Handler')
	})
})
