// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The dialog a handler divides a case in.
 *
 * `caseIncidentsTab.spec.js` beside this one asserts the incidents list from
 * the manifest. This file is the other half of the surface the split needed and
 * did not have: `CaseSplitPolicy` and `CaseSplitPlan` shipped able to decide
 * everything about a division nobody could ask for.
 *
 * THE FIRST THING ASSERTED IS THAT NOTHING IS TICKED. Which document belongs to
 * which half is a judgement about content, and a default selection is a
 * judgement that gets confirmed rather than made.
 *
 * THE SECOND IS THE CONTRADICTION. A party ticked as moving AND as on both
 * halves is two instructions, and a dialog that sent both would let the server
 * pick one of them, silently.
 *
 * THE THIRD IS THE PAYLOAD. A dialog that ticked correctly and posted the wrong
 * field name would look right on screen and divide nothing, which is the
 * failure mode a mounted test exists for.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CaseSplitDialog from '../../src/dialogs/CaseSplitDialog.vue'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(
	path.join(ROOT, 'src', 'registry.js'),
	'utf8',
)
const register = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'),
		'utf8',
	),
)

const caseDetail = manifest.pages.find((p) => p.id === 'CaseDetail')

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

/**
 * Mount the dialog over a case holding two documents and one party.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountDialog() {
	const axios = (await import('@nextcloud/axios')).default
	axios.get.mockImplementation((url) => {
		if (String(url).includes('caseDocument')) {
			return Promise.resolve({
				data: {
					results: [
						{ id: 'doc-1', title: 'Foto' },
						{ id: 'doc-2', title: 'Brief' },
					],
				},
			})
		}

		return Promise.resolve({
			data: { results: [{ id: 'role-1', roleType: 'belanghebbende', name: 'Buurman' }] },
		})
	})
	axios.post.mockResolvedValue({ data: {} })

	const wrapper = mount(CaseSplitDialog, {
		props: { caseId: 'case-1' },
		global: {
			stubs: {
				NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				NcLoadingIcon: { template: '<span class="loading" />' },
				NcTextField: {
					props: { modelValue: { type: String, default: '' } },
					template:
						'<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
				},
				NcCheckboxRadioSwitch: {
					props: { modelValue: { type: Boolean, default: false } },
					template:
						'<button v-bind="$attrs" :aria-pressed="String(modelValue)" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></button>',
				},
				NcButton: {
					// NO EXPLICIT `$emit('click')`: the parent's handler already
					// falls through with `$attrs`, and emitting as well fires it
					// twice, which would count two posts for one press.
					props: { disabled: { type: Boolean, default: false } },
					template: '<button v-bind="$attrs" :disabled="disabled"><slot /></button>',
				},
			},
		},
	})
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()

	return wrapper
}

describe('the Split dialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('ticks nothing for the handler', async () => {
		const wrapper = await mountDialog()

		const ticked = wrapper
			.findAll('[aria-pressed]')
			.filter((node) => node.attributes('aria-pressed') === 'true')

		expect(
			ticked,
			'A default selection is a judgement that gets confirmed rather than made.',
		).toHaveLength(0)
		expect(
			wrapper.find('[data-testid="case-split-confirm"]').attributes('disabled'),
		).toBeDefined()
	})

	it('refuses a party ticked as moving and as on both halves', async () => {
		const wrapper = await mountDialog()

		await wrapper.find('[data-testid="case-split-title"]').setValue('De tweede klacht')
		await wrapper.find('[data-testid="case-split-party-role-1"]').trigger('click')
		await wrapper.find('[data-testid="case-split-party-both-role-1"]').trigger('click')
		await wrapper.vm.$nextTick()

		expect(
			wrapper.find('[data-testid="case-split-confirm"]').attributes('disabled'),
			'Two contradictory instructions, so the button refuses rather than the server picking one.',
		).toBeDefined()
	})

	it('sends what was ticked, under the names the endpoint reads', async () => {
		const axios = (await import('@nextcloud/axios')).default
		const wrapper = await mountDialog()

		await wrapper.find('[data-testid="case-split-title"]').setValue('De tweede klacht')
		await wrapper.find('[data-testid="case-split-document-doc-1"]').trigger('click')
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="case-split-confirm"]').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(String(axios.post.mock.calls[0][0])).toContain('/split')
		expect(
			axios.post.mock.calls[0][1],
			'A dialog that ticked correctly and posted the wrong field name would look right and divide nothing.',
		).toEqual({
			title: 'De tweede klacht',
			documents: ['doc-1'],
			parties: [],
			partiesOnBoth: [],
		})
	})

	it('shows the server\'s own refusal rather than one of its own', async () => {
		const axios = (await import('@nextcloud/axios')).default
		const wrapper = await mountDialog()
		axios.post.mockRejectedValue({
			response: {
				data: {
					message:
						'This case type does not allow documents to be divided. It allows parties to be divided.',
				},
			},
		})

		await wrapper.find('[data-testid="case-split-title"]').setValue('De tweede klacht')
		await wrapper.find('[data-testid="case-split-document-doc-1"]').trigger('click')
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="case-split-confirm"]').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()

		expect(
			wrapper.find('[data-testid="case-split-error"]').text(),
			'CaseSplitPolicy names what may still be divided; a sentence written here would lose that half.',
		).toContain('It allows parties to be divided.')
	})
})

describe('the way the dialog is reached', () => {
	it('is a header action on the case, hidden once the case is closed', () => {
		const action = caseDetail.config.headerActions.find((a) => a.id === 'case-split')

		expect(
			action,
			'CaseSplitPolicy and CaseSplitPlan could decide everything about a division nobody could ask for.',
		).toBeDefined()
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('CaseSplitDialog')
		expect(
			action.visibleWhen,
			'A closed case is a record of what was decided; dividing one after the fact changes what a decision was about.',
		).toEqual({ field: 'isFinalStatus', op: 'neq', value: true })
		expect(
			Object.keys(action),
			'The v2 manifest schema rejects `_note` on a header action, which is why the merge action fails the validator.',
		).not.toContain('_note')
	})

	it('is registered as a modal, so the action resolves to something', () => {
		expect(registrySource).toContain('CaseSplitDialog: {')
		expect(registrySource).toContain("kind: 'modal'")
	})

	it('has somewhere to store the reference a split leaves behind', () => {
		const properties = register.components.schemas.case.properties

		expect(
			properties.splitInto,
			'CaseSplitPlan produced references and the schema had nowhere to put them.',
		).toBeDefined()
		expect(properties.splitMovedItems.type).toBe('array')
		expect(properties.splitNote).toBeDefined()
		expect(properties.splitFrom).toBeDefined()
	})
})
