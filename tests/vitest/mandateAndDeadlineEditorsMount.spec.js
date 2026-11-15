// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The mandate editor and the deadline definition editor mounted: what each
 * field writes into the form, which errors the template shows, and what the
 * buttons emit.
 *
 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-07-admin-ui/tasks.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { byId } from './helpers/ncFormStubs.js'

vi.mock('@nextcloud/vue', async () => (await import('./helpers/ncFormStubs.js')).ncFormStubs())
vi.mock('vue-material-design-icons/ContentSave.vue', () => ({ default: { name: 'ContentSave', render: () => null } }))

const { default: MandaatEditor } = await import('../../src/modals/MandaatEditor.vue')
const { default: DeadlineDefinitionEditor } = await import('../../src/modals/DeadlineDefinitionEditor.vue')

const ROLES = [{ id: 'teamleider', label: 'Teamleider' }, { id: 'afdelingshoofd', label: 'Afdelingshoofd' }]

describe('MandaatEditor mounted', () => {
	it('fills every field through its input and emits the form on save', async () => {
		const wrapper = mount(MandaatEditor, { props: { roleOptions: ROLES } })

		expect(wrapper.find('.nc-frame__name').text()).toBe('New mandaat')
		expect(byId(wrapper, 'NcSelect', 'me-type').findAll('li').map((li) => li.text()))
			.toEqual(['Decision authority', 'Signing authority', 'Mandated authority', 'Doormandaat'])
		expect(byId(wrapper, 'NcSelect', 'me-rol').findAll('li').map((li) => li.text())).toEqual(['Teamleider', 'Afdelingshoofd'])

		byId(wrapper, 'NcTextField', 'me-num').vm.$emit('update:modelValue', 'M-2026-014')
		await wrapper.find('#me-omschr').setValue('Besluiten op aanvragen omgevingsvergunning')
		byId(wrapper, 'NcSelect', 'me-type').vm.$emit('update:modelValue', { id: 'ondertekeningsbevoegdheid' })
		byId(wrapper, 'NcTextField', 'me-grond').vm.$emit('update:modelValue', 'Awb art. 10:3')
		await wrapper.find('#me-voorw').setValue('{ "maxBedrag": 50000 }')
		await wrapper.find('#me-inwerkingtreding').setValue('2026-12-01')
		await wrapper.find('#me-verval').setValue('2027-12-01')
		byId(wrapper, 'NcSelect', 'me-rol').vm.$emit('update:modelValue', ROLES[1])
		await flushPromises()

		expect(byId(wrapper, 'NcSelect', 'me-rol').props('modelValue')).toEqual(ROLES[1])
		await wrapper.find('.nc-button--primary').trigger('click')

		expect(wrapper.emitted('save')).toEqual([[{
			mandateNumber: 'M-2026-014',
			description: 'Besluiten op aanvragen omgevingsvergunning',
			competenceType: 'ondertekeningsbevoegdheid',
			legalBasis: 'Awb art. 10:3',
			inWerkingtreding: '2026-12-01',
			vervaldatum: '2027-12-01',
			toegewezenRol: 'afdelingshoofd',
			terms: { maxBedrag: 50000 },
		}]])
		expect(wrapper.find('.nc-button--primary').text()).toBe('Save')
	})

	it('shows every required-field error, with an emptied type and invalid JSON, and saves nothing', async () => {
		const wrapper = mount(MandaatEditor, {
			props: { mandaat: { mandateNumber: '', toegewezenRol: 'teamleider' }, roleOptions: ROLES },
		})
		expect(wrapper.find('.nc-frame__name').text()).toBe('Edit mandaat')
		expect(byId(wrapper, 'NcSelect', 'me-rol').props('modelValue')).toEqual(ROLES[0])

		byId(wrapper, 'NcSelect', 'me-type').vm.$emit('update:modelValue', null)
		byId(wrapper, 'NcSelect', 'me-rol').vm.$emit('update:modelValue', null)
		await wrapper.find('#me-voorw').setValue('{ maxBedrag: }')
		await wrapper.find('.nc-button--primary').trigger('click')
		await flushPromises()

		expect(wrapper.emitted('save')).toBeUndefined()
		expect(wrapper.vm.form.toegewezenRol).toBe('')
		expect(wrapper.findAll('.field-error').map((s) => s.text()))
			.toEqual(['Omschrijving is required', 'Bevoegdheidstype is required', 'Voorwaarden must be valid JSON'])
		expect(byId(wrapper, 'NcTextField', 'me-num').attributes('data-helper')).toBe('Mandaatnummer is required')
		expect(byId(wrapper, 'NcTextField', 'me-grond').classes()).toContain('nc-text--error')
		// An emptied type falls back to the first option for display only.
		expect(byId(wrapper, 'NcSelect', 'me-type').props('modelValue').id).toBe('beslissingsbevoegdheid')
	})

	it('closes from the cancel button and from the modal', async () => {
		const wrapper = mount(MandaatEditor)

		await wrapper.find('.nc-button--secondary').trigger('click')
		wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')

		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})

describe('DeadlineDefinitionEditor mounted', () => {
	const ZAAKTYPES = [{ id: 'omgevingsvergunning', label: 'Omgevingsvergunning' }]

	it('fills every field through its input and emits the new version on save', async () => {
		const wrapper = mount(DeadlineDefinitionEditor, { props: { zaaktypeOptions: ZAAKTYPES } })
		expect(wrapper.find('.nc-frame__name').text()).toBe('New term definition')
		expect(byId(wrapper, 'NcSelect', 'td-zaaktype').props('modelValue')).toBe(null)
		expect(wrapper.find('#td-ext-dagen').exists()).toBe(false)

		byId(wrapper, 'NcSelect', 'td-zaaktype').vm.$emit('update:modelValue', ZAAKTYPES[0])
		byId(wrapper, 'NcTextField', 'td-grondslag').vm.$emit('update:modelValue', 'Awb art. 4:13')
		byId(wrapper, 'NcTextField', 'td-duur').vm.$emit('update:modelValue', '56')
		byId(wrapper, 'NcSelect', 'td-counting-mode').vm.$emit('update:modelValue', { id: 'workingDays' })
		byId(wrapper, 'NcSelect', 'td-categorie').vm.$emit('update:modelValue', { id: 'beroep' })
		wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(byId(wrapper, 'NcSelect', 'td-zaaktype').props('modelValue')).toEqual(ZAAKTYPES[0])
		byId(wrapper, 'NcTextField', 'td-ext-dagen').vm.$emit('update:modelValue', '14')
		await wrapper.find('.nc-button--primary').trigger('click')

		expect(wrapper.emitted('save')).toEqual([[{
			case_type: 'omgevingsvergunning',
			basis: 'Awb art. 4:13',
			duurDagen: 56,
			category: 'beroep',
			extendable: true,
			maxExtensionDagen: 14,
			countingMode: 'workingDays',
		}]])
		expect(wrapper.find('.nc-button--primary').text()).toBe('Save new version')
	})

	it('takes a typed zaaktype by label or as text, and resets cleared selects to their defaults', async () => {
		const wrapper = mount(DeadlineDefinitionEditor, {
			props: { definition: { caseType: 'Bezwaar', case_type: 'bezwaar', countingMode: 'workingDays', category: 'herstel', extendable: true, maxExtensionDagen: 7 } },
		})
		expect(wrapper.find('.nc-frame__name').text()).toBe('New version of Bezwaar')
		expect(byId(wrapper, 'NcSelect', 'td-zaaktype').props('modelValue')).toEqual({ id: 'bezwaar', label: 'bezwaar' })
		expect(byId(wrapper, 'NcTextField', 'td-ext-dagen').props('modelValue')).toBe('7')

		const zaaktype = byId(wrapper, 'NcSelect', 'td-zaaktype')
		zaaktype.vm.$emit('update:modelValue', { label: 'handhaving' })
		expect(wrapper.vm.form.case_type).toBe('handhaving')
		zaaktype.vm.$emit('update:modelValue', 'subsidie')
		expect(wrapper.vm.form.case_type).toBe('subsidie')
		zaaktype.vm.$emit('update:modelValue', null)
		expect(wrapper.vm.form.case_type).toBe('')

		byId(wrapper, 'NcSelect', 'td-counting-mode').vm.$emit('update:modelValue', null)
		byId(wrapper, 'NcSelect', 'td-categorie').vm.$emit('update:modelValue', null)
		byId(wrapper, 'NcTextField', 'td-ext-dagen').vm.$emit('update:modelValue', 'veel')
		expect(wrapper.vm.form.countingMode).toBe('calendarDays')
		expect(wrapper.vm.form.category).toBe('')
		expect(wrapper.vm.form.maxExtensionDagen).toBe(0)
		await flushPromises()
		expect(byId(wrapper, 'NcSelect', 'td-categorie').props('modelValue').id).toBe('beslis')
	})

	it('shows the required-field errors and saves nothing; closes from cancel and the modal', async () => {
		const wrapper = mount(DeadlineDefinitionEditor)

		byId(wrapper, 'NcTextField', 'td-duur').vm.$emit('update:modelValue', 'nul')
		await wrapper.find('.nc-button--primary').trigger('click')
		await flushPromises()

		expect(wrapper.emitted('save')).toBeUndefined()
		expect(wrapper.findAll('.field-error').map((s) => s.text()))
			.toEqual(['Zaaktype is required', 'Wettelijke grondslag is required', 'Duration must be at least 1 day'])

		await wrapper.find('.nc-button--secondary').trigger('click')
		wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
