// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Handling teams on a case type's General tab: the Nextcloud groups that
 * handle cases of the type besides its default group. Choosing one writes
 * the whole handling block back, so the other switches survive.
 *
 * @spec openspec/changes/case-type-handling-teams/specs/case-types/spec.md#requirement-a-case-type-names-the-teams-that-handle-it-req-ct-44
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

/**
 * A component stub that keeps the props the test reads.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function stub(name) {
	return {
		name,
		props: {
			modelValue: {
				type: [Array, Object, String, Boolean, Number],
				default: null,
			},
			options: { type: Array, default: () => [] },
			inputLabel: { type: String, default: '' },
			multiple: { type: Boolean, default: false },
		},
		emits: ['update:modelValue'],
		render() {
			return h('div', { class: name, 'data-label': this.inputLabel })
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcSelect: stub('NcSelect'),
	NcTextField: stub('NcTextField'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url) => url,
	generateOcsUrl: (url) => '/ocs/v2.php/' + url,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))

const { default: GeneralTab } =
	await import('../../src/views/settings/tabs/GeneralTab.vue')

const GROUPS = {
	ocs: {
		data: {
			groups: [
				{ id: 'vergunningen', displayname: 'Vergunningen' },
				{ id: 'handhaving', displayname: 'Toezicht en handhaving' },
			],
		},
	},
}

/**
 * Mount the tab over a form with the given handling block.
 *
 * @param {object} handling The handling block.
 * @return {Promise<object>} The wrapper, after the groups loaded.
 */
async function mountWith(handling) {
	axios.get.mockImplementation(async (url) =>
		url.includes('cloud/groups') ? { data: GROUPS } : { data: {} },
	)
	const wrapper = mount(GeneralTab, { props: { form: { handling } } })
	await flushPromises()

	return wrapper
}

/**
 * The handling teams picker.
 *
 * @param {object} wrapper The mounted tab.
 * @return {object} The select stub.
 */
function teamsSelect(wrapper) {
	return wrapper
		.findAllComponents({ name: 'NcSelect' })
		.find((select) => select.props('inputLabel') === 'Handling teams')
}

describe('GeneralTab handling teams', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('offers the Nextcloud groups, sorted by name, as a multiple choice', async () => {
		const wrapper = await mountWith({ defaultGroup: 'vergunningen' })
		const select = teamsSelect(wrapper)

		expect(select.props('multiple')).toBe(true)
		expect(select.props('options')).toEqual([
			{ id: 'handhaving', label: 'Toezicht en handhaving' },
			{ id: 'vergunningen', label: 'Vergunningen' },
		])
	})

	it('writes the chosen teams into the whole handling block', async () => {
		const handling = {
			defaultGroup: 'vergunningen',
			defaultHandler: 'lars',
			automaticMessages: ['extension'],
			intakeScreen: 'x',
		}
		const wrapper = await mountWith(handling)

		teamsSelect(wrapper).vm.$emit('update:modelValue', [
			{ id: 'handhaving', label: 'Toezicht en handhaving' },
		])

		expect(wrapper.emitted('update')).toEqual([
			['handling', { ...handling, teams: ['handhaving'] }],
		])
	})

	it('keeps a declared team the instance no longer has, under its id', async () => {
		const wrapper = await mountWith({ teams: ['opgeheven'] })
		const select = teamsSelect(wrapper)

		expect(select.props('modelValue')).toEqual([
			{ id: 'opgeheven', label: 'opgeheven' },
		])
		expect(select.props('options')).toContainEqual({
			id: 'opgeheven',
			label: 'opgeheven',
		})
	})
})
