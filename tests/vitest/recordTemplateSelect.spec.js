// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A form offers the named record templates of its schema.
 *
 * The answer the double gives is the body of OpenRegister's
 * RecordTemplatesController::index on development (12 Oct 2026):
 * `{results: [RecordTemplate::jsonSerialize()], total}`.
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/record-template-start/spec.md#requirement-a-form-offers-the-named-templates-of-its-schema-req-rts-002
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RecordTemplateSelect from '../../src/components/RecordTemplateSelect.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))

const TEMPLATE = {
	id: 3,
	uuid: '77777777-7777-4777-8777-777777777777',
	name: 'Ruimtelijke verzoeken',
	description: '',
	register: '5',
	schema: '42',
	values: { terms: 'Stationsweg' },
	owner: 'pjansen',
	isPublic: true,
	sharedWith: [],
	'@self': { access: 'read' },
	created: '2026-10-11T09:00:00+00:00',
	updated: '2026-10-11T09:00:00+00:00',
}

const NcSelect = {
	props: ['options', 'inputLabel', 'modelValue'],
	emits: ['update:modelValue'],
	template:
		'<div class="select" :data-label="inputLabel"><button v-for="option in options" :key="option.uuid" :data-uuid="option.uuid" @click="$emit(\'update:modelValue\', option)">{{ option.name }}</button><button class="clear" @click="$emit(\'update:modelValue\', null)" /></div>',
}

/**
 * Mount the select for a schema.
 *
 * @param {string} schema The schema id.
 * @param {Function} answer What GET record-templates answers.
 * @return {Promise<{wrapper: object, axios: object}>} The select and the axios double.
 */
async function mountSelect(schema, answer) {
	const axios = (await import('@nextcloud/axios')).default
	axios.get.mockImplementation(answer)
	const wrapper = mount(RecordTemplateSelect, {
		props: { schema },
		global: { stubs: { NcSelect } },
	})
	await flushPromises()
	return { wrapper, axios }
}

describe('RecordTemplateSelect', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('lists the templates of its schema from OpenRegister and hands over the chosen one', async () => {
		const { wrapper, axios } = await mountSelect('42', () =>
			Promise.resolve({ data: { results: [TEMPLATE], total: 1 } }),
		)

		expect(axios.get).toHaveBeenCalledWith(
			expect.stringContaining('/apps/openregister/api/record-templates'),
			{ params: { schema: '42' } },
		)
		expect(wrapper.find('.select').attributes('data-label')).toBe(
			'Start from a template',
		)
		await wrapper.find(`[data-uuid="${TEMPLATE.uuid}"]`).trigger('click')
		expect(wrapper.emitted('apply')[0][0]).toEqual({
			uuid: TEMPLATE.uuid,
			name: 'Ruimtelijke verzoeken',
			values: { terms: 'Stationsweg' },
		})

		await wrapper.find('.clear').trigger('click')
		expect(wrapper.emitted('apply')[1][0]).toBeNull()
	})

	it('draws nothing when there is no template, the list cannot be read, or the schema is unknown', async () => {
		const empty = await mountSelect('42', () =>
			Promise.resolve({ data: { results: [], total: 0 } }),
		)
		expect(empty.wrapper.find('.select').exists()).toBe(false)

		const failed = await mountSelect('42', () =>
			Promise.reject(new Error('404')),
		)
		expect(failed.wrapper.find('.select').exists()).toBe(false)

		const odd = await mountSelect('42', () =>
			Promise.resolve({ data: { error: 'User not authenticated' } }),
		)
		expect(odd.wrapper.find('.select').exists()).toBe(false)

		vi.clearAllMocks()
		const unknown = await mountSelect('', () =>
			Promise.resolve({ data: { results: [TEMPLATE], total: 1 } }),
		)
		expect(unknown.axios.get).not.toHaveBeenCalled()
		expect(unknown.wrapper.find('.select').exists()).toBe(false)
	})

	it('drops a row that cannot be chosen and always hands over a values object', async () => {
		const { wrapper } = await mountSelect('42', () =>
			Promise.resolve({
				data: {
					results: [
						{ name: 'No uuid' },
						{ uuid: 'u-1', name: 'List values', values: [] },
					],
				},
			}),
		)

		expect(wrapper.findAll('[data-uuid]')).toHaveLength(1)
		await wrapper.find('[data-uuid="u-1"]').trigger('click')
		expect(wrapper.emitted('apply')[0][0].values).toEqual({})
	})
})
