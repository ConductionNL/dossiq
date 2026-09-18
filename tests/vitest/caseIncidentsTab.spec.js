// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Incidents section, and the Split dialog beside it.
 *
 * THE FIRST THING ASSERTED IS THE SORT. An incidents list ordered on the
 * record's creation moment reads the pattern backwards the first time somebody
 * writes up an old report, and a case whose reports read in the wrong order is
 * a case whose history says the opposite of what happened. The manifest is
 * where that is decided, so the manifest is where it is checked.
 *
 * THE SECOND IS THAT BOTH MOMENTS ARE COLUMNS. One of them alone hides the
 * delay: eventDate alone loses the fact that the report was written up three
 * weeks late, and recordedAt alone loses when it happened.
 *
 * THE THIRD IS THAT THE SPLIT DIALOG TICKS NOTHING AND REFUSES A CONTRADICTION.
 * A party ticked as moving AND as on both halves is two instructions, and a
 * dialog that sent both would let the server pick one.
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
const register = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'),
		'utf8',
	),
)
const registrySource = fs.readFileSync(
	path.join(ROOT, 'src', 'registry.js'),
	'utf8',
)

const caseDetail = manifest.pages.find((p) => p.id === 'CaseDetail')

/**
 * The Incidents section's widget, from the Work panel.
 *
 * @return {object} The widget.
 */
function incidentsWidget() {
	const work = caseDetail.config.widgets.find((w) => w.id === 'case-work-panel')
	const section = work.content.sections.find((s) => s.label === 'Incidents')

	return section?.widget
}

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

describe('the Incidents section on the case', () => {
	it('lists the reports in the order they happened', () => {
		const widget = incidentsWidget()

		expect(widget, 'The section has to exist before anything else here means anything.').toBeDefined()
		expect(widget.content.schema).toBe('incident')
		expect(widget.content.filter.case).toBe('@objectId')
		expect(
			widget.content.sort,
			'Sorted on when it happened, not on when somebody typed it up.',
		).toEqual({ field: 'eventDate', dir: 'asc' })
	})

	it('shows both moments, so the recording delay is on the row', () => {
		const keys = incidentsWidget().content.columns.map((c) => c.key)

		expect(keys).toContain('eventDate')
		expect(
			keys,
			'Without the recording moment beside the event date, a report written up three weeks late looks like one written up the same day.',
		).toContain('recordedAt')
		expect(keys).toContain('reporter')
		expect(keys, 'Its own owner, which is the point of REQ-INC-02.').toContain('assignee')
		expect(keys).toContain('outcome')
	})

	it('describes an incident as a dated event and not as a sub-case', () => {
		const incident = register.components.schemas.incident
		const keys = Object.keys(incident.properties)

		expect(keys).toEqual(
			expect.arrayContaining([
				'case',
				'eventDate',
				'recordedAt',
				'reporter',
				'description',
				'assignee',
				'state',
				'outcome',
			]),
		)
		expect(incident.properties.state.enum).toEqual([
			'open',
			'in-behandeling',
			'afgehandeld',
		])
		for (const forbidden of ['deadline', 'decision', 'identifier']) {
			expect(
				keys,
				`An incident with a ${forbidden} is a deelzaak wearing another name.`,
			).not.toContain(forbidden)
		}
	})
})

describe('the Split dialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

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
					data: { results: [{ id: 'doc-1', title: 'Foto' }, { id: 'doc-2', title: 'Brief' }] },
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

	it('ticks nothing for the handler', async () => {
		const wrapper = await mountDialog()

		const ticked = wrapper
			.findAll('[aria-pressed]')
			.filter((node) => node.attributes('aria-pressed') === 'true')

		expect(
			ticked,
			'Which document belongs to which half is a judgement about content, and a default selection is a judgement that gets confirmed.',
		).toHaveLength(0)
		expect(wrapper.find('[data-testid="case-split-confirm"]').attributes('disabled')).toBeDefined()
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

	it('sends what was ticked, and nothing else', async () => {
		const axios = (await import('@nextcloud/axios')).default
		const wrapper = await mountDialog()

		await wrapper.find('[data-testid="case-split-title"]').setValue('De tweede klacht')
		await wrapper.find('[data-testid="case-split-document-doc-1"]').trigger('click')
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="case-split-confirm"]').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(String(axios.post.mock.calls[0][0])).toContain('/split')
		expect(axios.post.mock.calls[0][1]).toEqual({
			title: 'De tweede klacht',
			documents: ['doc-1'],
			parties: [],
			partiesOnBoth: [],
		})
	})

	it('is registered as a modal the header action can open', () => {
		const action = caseDetail.config.headerActions.find((a) => a.id === 'case-split')

		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('CaseSplitDialog')
		expect(
			action.visibleWhen,
			'A closed case is a record of what was decided; dividing one after the fact changes what a decision was about.',
		).toEqual({ field: 'isFinalStatus', op: 'neq', value: true })
		expect(registrySource).toContain("CaseSplitDialog: {")
	})
})
