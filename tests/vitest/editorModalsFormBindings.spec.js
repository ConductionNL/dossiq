// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * MandaatEditor and DeadlineDefinitionEditor, mounted: every input writes the
 * form field it labels (cleared selects fall back to their defaults), the
 * extension field only shows once an extension is allowed, and Cancel, the
 * modal's close and Save do what they say.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

/**
 * A rendering stand-in for a Nextcloud control that keeps its props, id and slots.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
function control(name) {
	return defineComponent({
		name,
		inheritAttrs: false,
		props: ['name', 'size', 'modelValue', 'options', 'disabled', 'variant', 'type', 'error', 'helperText', 'inputLabel', 'placeholder', 'taggable'],
		emits: ['close', 'update:modelValue', 'click'],
		render() {
			return h('div', { class: name, id: this.$attrs.id }, [
				...(this.$slots.icon?.() ?? []),
				...(this.$slots.default?.() ?? []),
			])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcCheckboxRadioSwitch: control('NcCheckboxRadioSwitch'),
	NcLoadingIcon: control('NcLoadingIcon'),
	NcModal: control('NcModal'),
	NcSelect: control('NcSelect'),
	NcTextField: control('NcTextField'),
}))
vi.mock('vue-material-design-icons/ContentSave.vue', () => ({ default: control('ContentSave') }))

const { default: MandaatEditor } = await import('../../src/modals/MandaatEditor.vue')
const { default: DeadlineDefinitionEditor } = await import('../../src/modals/DeadlineDefinitionEditor.vue')

/**
 * The control rendered with the given id.
 *
 * @param {object} wrapper The mounted modal.
 * @param {string} name    The control's component name.
 * @param {string} id      Its id.
 * @return {object} The component wrapper.
 */
function byId(wrapper, name, id) {
	const found = wrapper.findAllComponents({ name }).find((c) => c.attributes('id') === id)
	if (!found) {
		throw new Error(`no ${name}#${id}`)
	}
	return found
}

/**
 * Emit a model update from a control.
 *
 * @param {object} wrapper The mounted modal.
 * @param {string} name    The control's component name.
 * @param {string} id      Its id.
 * @param {*}      value   The new value.
 * @return {void}
 */
function input(wrapper, name, id, value) {
	byId(wrapper, name, id).vm.$emit('update:modelValue', value)
}

describe('MandaatEditor form bindings', () => {
	it('writes every field from its input, a cleared select empties its field', async () => {
		const roles = [{ id: 'r1', label: 'Teamleider' }]
		const w = mount(MandaatEditor, { props: { roleOptions: roles } })

		input(w, 'NcTextField', 'me-num', 'M-2026-07')
		input(w, 'NcTextField', 'me-grond', 'Awb 10:3')
		input(w, 'NcSelect', 'me-type', { id: 'doormandaat' })
		input(w, 'NcSelect', 'me-rol', roles[0])
		await w.find('#me-omschr').setValue('Vergunningen tot 50k')
		await w.find('#me-voorw').setValue('{"maxBedrag": 50000}')
		await w.find('#me-inwerkingtreding').setValue('2026-07-01')
		await w.find('#me-verval').setValue('2027-07-01')

		expect(w.vm.form).toMatchObject({
			mandateNumber: 'M-2026-07',
			legalBasis: 'Awb 10:3',
			competenceType: 'doormandaat',
			toegewezenRol: 'r1',
			description: 'Vergunningen tot 50k',
			inWerkingtreding: '2026-07-01',
			vervaldatum: '2027-07-01',
		})
		expect(w.vm.voorwaardenJson).toBe('{"maxBedrag": 50000}')

		input(w, 'NcSelect', 'me-type', null)
		input(w, 'NcSelect', 'me-rol', null)
		expect([w.vm.form.competenceType, w.vm.form.toegewezenRol]).toEqual(['', ''])
	})

	it('shows the field errors a refused save names', async () => {
		const w = mount(MandaatEditor)
		input(w, 'NcSelect', 'me-type', null)
		await w.find('#me-voorw').setValue('{ nope')

		w.findAllComponents({ name: 'NcButton' })[1].vm.$emit('click')
		await flushPromises()

		expect(w.findAll('.field-error')).toHaveLength(3)
		expect(byId(w, 'NcTextField', 'me-num').props('error')).toBe(true)
		expect(byId(w, 'NcTextField', 'me-grond').props('helperText')).toBe(w.vm.errors.legalBasis)
		expect(w.emitted('save')).toBeUndefined()
	})

	it('saves through the Save button and closes on Cancel and on the modal close', async () => {
		const mandaat = { mandateNumber: 'M-1', description: 'Permits', competenceType: 'doormandaat', legalBasis: 'Awb 10:3', terms: {} }
		const w = mount(MandaatEditor, { props: { mandaat } })
		const [cancel, save] = w.findAllComponents({ name: 'NcButton' })

		save.vm.$emit('click')
		await flushPromises()
		expect(w.emitted('save')[0][0]).toMatchObject({ mandateNumber: 'M-1', competenceType: 'doormandaat' })

		cancel.vm.$emit('click')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(w.emitted('close')).toHaveLength(2)
	})
})

describe('DeadlineDefinitionEditor form bindings', () => {
	it('writes every field from its input', async () => {
		const w = mount(DeadlineDefinitionEditor, { props: { caseTypes: [{ id: 'ct-1', slug: 'omgevingsvergunning' }] } })

		input(w, 'NcSelect', 'td-zaaktype', { id: 'ct-1', label: 'Omgevingsvergunning' })
		input(w, 'NcTextField', 'td-grondslag', 'Wabo 3.9')
		input(w, 'NcTextField', 'td-duur', '56')
		input(w, 'NcSelect', 'td-counting-mode', { id: 'workingDays' })
		input(w, 'NcSelect', 'td-categorie', { id: 'beslis' })

		expect(w.vm.form).toMatchObject({ case_type: 'ct-1', basis: 'Wabo 3.9', duurDagen: 56, countingMode: 'workingDays', category: 'beslis' })
	})

	it('takes a typed zaaktype slug, and falls back when a select is cleared or a number is not one', () => {
		const w = mount(DeadlineDefinitionEditor)

		input(w, 'NcSelect', 'td-zaaktype', { label: 'typed-slug' })
		expect(w.vm.form.case_type).toBe('typed-slug')
		input(w, 'NcSelect', 'td-zaaktype', 'bare-slug')
		expect(w.vm.form.case_type).toBe('bare-slug')
		input(w, 'NcSelect', 'td-zaaktype', null)
		expect(w.vm.form.case_type).toBe('')

		input(w, 'NcSelect', 'td-counting-mode', { id: 'workingDays' })
		input(w, 'NcSelect', 'td-counting-mode', null)
		expect(w.vm.form.countingMode).toBe('calendarDays')

		input(w, 'NcSelect', 'td-categorie', null)
		expect(w.vm.form.category).toBe('')

		input(w, 'NcTextField', 'td-duur', 'abc')
		expect(w.vm.form.duurDagen).toBe(0)
	})

	it('shows the extension field only once an extension is allowed', async () => {
		const w = mount(DeadlineDefinitionEditor)
		const extendable = w.findComponent({ name: 'NcCheckboxRadioSwitch' })

		extendable.vm.$emit('update:modelValue', false)
		await w.vm.$nextTick()
		expect(w.find('#td-ext-dagen').exists()).toBe(false)

		extendable.vm.$emit('update:modelValue', true)
		await w.vm.$nextTick()
		input(w, 'NcTextField', 'td-ext-dagen', '42')
		expect(w.vm.form).toMatchObject({ extendable: true, maxExtensionDagen: 42 })
		input(w, 'NcTextField', 'td-ext-dagen', '')
		expect(w.vm.form.maxExtensionDagen).toBe(0)
	})

	it('saves through the Save button and closes on Cancel and on the modal close', async () => {
		const definition = { case_type: 'ct-1', basis: 'Awb 4:13', duurDagen: 56, category: 'beslis' }
		const w = mount(DeadlineDefinitionEditor, { props: { definition } })
		const [cancel, save] = w.findAllComponents({ name: 'NcButton' })

		save.vm.$emit('click')
		await flushPromises()
		expect(w.emitted('save')[0][0]).toMatchObject({ case_type: 'ct-1', basis: 'Awb 4:13', duurDagen: 56 })

		cancel.vm.$emit('click')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(w.emitted('close')).toHaveLength(2)
	})
})
