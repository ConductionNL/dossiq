// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Properties tab offers what the platform publishes, and nothing else.
 *
 * Three behaviours are pinned here, and each of them shipped broken:
 *
 *  - the type picker was eight hard-coded options, so no administrator could
 *    declare an array, a file, a geo point or a reference, all of which the
 *    engine has validated all along;
 *  - choosing `enum` produced a choice list with no input to fill it, so the
 *    case form got a dropdown with nothing in it and nothing said so;
 *  - a type this instance does not know had no handling at all, and the next
 *    save would have written it back as whatever the picker happened to hold.
 *
 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const saveObject = vi.fn()
const deleteObject = vi.fn()
const fetchCollection = vi.fn()
const getError = vi.fn(() => '')

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		fetchCollection,
		saveObject,
		deleteObject,
		getError,
	}),
}))

/**
 * A stand-in for one Nextcloud component.
 *
 * @param {string} name The component name.
 * @param {string} tag The element it renders.
 * @return {object} The stub component.
 */
function stub(name, tag = 'div') {
	return defineComponent({
		name,
		props: [
			'modelValue',
			'label',
			'error',
			'type',
			'variant',
			'disabled',
			'helperText',
		],
		emits: ['update:modelValue'],
		render() {
			return h(
				tag,
				{
					class: name,
					'data-label': this.label,
					onClick: () => this.$emit('click'),
				},
				this.$slots.default ? this.$slots.default() : '',
			)
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcTextField: stub('NcTextField'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
}))
vi.mock('vue-material-design-icons/Delete.vue', () => ({
	default: stub('DeleteIcon'),
}))
vi.mock('vue-material-design-icons/Pencil.vue', () => ({
	default: stub('PencilIcon'),
}))

const { resetPropertyVocabulary } =
	await import('../../src/services/propertyVocabulary.js')
const { VOCABULARY_SNAPSHOT } =
	await import('../../src/services/propertyVocabularySnapshot.js')
const { default: PropertiesTab } =
	await import('../../src/views/settings/tabs/PropertiesTab.vue')

/**
 * A vocabulary answer with the two types this test cares about.
 *
 * Deliberately not the snapshot: an instance that publishes its own list must
 * be the list the editor offers, and a test that passes on the snapshot too
 * cannot tell the two apart.
 *
 * @return {object} The vocabulary as the endpoint answers it.
 */
function instanceVocabulary() {
	return {
		types: [
			{
				type: 'string',
				category: 'text',
				constraints: ['format', 'pattern', 'maxLength', 'enum'],
				formats: ['date', 'markdown'],
				description: 'Text.',
			},
			{
				type: 'array',
				category: 'composite',
				constraints: ['items', 'enum'],
				formats: [],
				description: 'A list.',
			},
			{
				type: 'gravity',
				category: 'physics',
				constraints: [],
				formats: [],
				description: 'Only this instance knows it.',
			},
		],
		categories: ['text', 'composite', 'physics'],
		constraints: [],
		modifiers: [],
		passthrough: [],
		keys: ['type', 'title', 'description'],
		vendorExtensionPrefix: 'x-',
		counts: {},
	}
}

/**
 * Mount the tab with the definitions it should list.
 *
 * @param {Array<object>} definitions The stored property definitions.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountTab(definitions = []) {
	fetchCollection.mockImplementation((schema) =>
		Promise.resolve(schema === 'propertyDefinition' ? definitions : []),
	)
	const wrapper = mount(PropertiesTab, {
		props: { caseTypeId: 'ct-1', isCreate: false },
	})
	await flushPromises()
	return wrapper
}

describe('PropertiesTab', () => {
	beforeEach(() => {
		resetPropertyVocabulary()
		axios.get.mockReset()
		saveObject.mockReset()
		fetchCollection.mockReset()
		getError.mockReturnValue('')
		axios.get.mockResolvedValue({ data: instanceVocabulary() })
	})

	it('offers the types the instance publishes, and no others', async () => {
		const wrapper = await mountTab()
		const options = wrapper
			.find('#pd-add-type')
			.findAll('option')
			.map((option) => option.attributes('value'))
		expect(options).toEqual(['string', 'array', 'gravity'])
		expect(options).not.toContain('date')
		expect(options).not.toContain('json')
	})

	it('reads the instance list, not the built-in copy', async () => {
		const wrapper = await mountTab()
		expect(axios.get).toHaveBeenCalledWith(
			expect.stringContaining(
				'/apps/openregister/api/schemas/property-vocabulary',
			),
		)
		expect(wrapper.find('.pd-fields__notice').exists()).toBe(false)
	})

	it('falls back to the snapshot and says so when nothing answers', async () => {
		axios.get.mockRejectedValue(new Error('404'))
		const wrapper = await mountTab()
		const options = wrapper
			.find('#pd-add-type')
			.findAll('option')
			.map((option) => option.attributes('value'))
		expect(options).toEqual(VOCABULARY_SNAPSHOT.types.map((row) => row.type))
		const notice = wrapper.find('.pd-fields__notice')
		expect(notice.exists()).toBe(true)
		expect(notice.text()).toContain('built-in copy')
	})

	it('offers the formats the chosen type takes, and drops them when it does not', async () => {
		const wrapper = await mountTab()
		expect(wrapper.find('#pd-add-format').exists()).toBe(true)
		await wrapper.find('#pd-add-type').setValue('array')
		await flushPromises()
		expect(wrapper.find('#pd-add-format').exists()).toBe(false)
		expect(wrapper.vm.newForm.format).toBe(null)
		expect(wrapper.find('#pd-add-items').exists()).toBe(true)
	})

	it('offers an input for the choice list, which never existed', async () => {
		const wrapper = await mountTab()
		const fields = wrapper.findComponent({ name: 'PropertyDefinitionFields' })
		fields.vm.toggleChoiceList(true)
		await flushPromises()
		const input = wrapper.find('#pd-add-enum')
		expect(input.exists()).toBe(true)
		await input.setValue('one\ntwo\nthree')
		expect(wrapper.vm.newForm.enumValues).toEqual(['one', 'two', 'three'])
	})

	it('refuses an empty choice list rather than shipping one', async () => {
		const wrapper = await mountTab()
		const fields = wrapper.findComponent({ name: 'PropertyDefinitionFields' })
		wrapper.vm.applyNew({ name: 'Cadence' })
		fields.vm.toggleChoiceList(true)
		await flushPromises()
		await wrapper.vm.addProperty()
		expect(saveObject).not.toHaveBeenCalled()
		expect(wrapper.vm.addError).toContain('choice list needs values')
	})

	it('saves the choice list and leaves the switch out of the payload', async () => {
		saveObject.mockResolvedValue({ id: 'pd-9', name: 'Cadence' })
		const wrapper = await mountTab()
		const fields = wrapper.findComponent({ name: 'PropertyDefinitionFields' })
		wrapper.vm.applyNew({ name: 'Cadence' })
		fields.vm.toggleChoiceList(true)
		fields.vm.setEnumValues('weekly\nmonthly')
		await flushPromises()
		await wrapper.vm.addProperty()
		expect(saveObject).toHaveBeenCalledTimes(1)
		const saved = saveObject.mock.calls[0][1]
		expect(saved.enumValues).toEqual(['weekly', 'monthly'])
		expect(saved).not.toHaveProperty('choiceList')
	})

	it('keeps a type this instance does not know, and never opens it for edit', async () => {
		const stored = {
			id: 'pd-1',
			name: 'Route',
			propertyType: 'polyline',
			defaultValue: '52.1,5.3',
		}
		const wrapper = await mountTab([stored])
		wrapper.vm.startEdit(stored)
		await flushPromises()
		expect(wrapper.vm.editingId).toBe(null)
		expect(wrapper.vm.error).toContain('polyline')
		expect(saveObject).not.toHaveBeenCalled()
		expect(stored.defaultValue).toBe('52.1,5.3')
		expect(wrapper.text()).toContain('polyline')
	})

	it('opens a type dossiq used to offer, and names what replaces it', async () => {
		axios.get.mockRejectedValue(new Error('404'))
		const stored = { id: 'pd-2', name: 'Received on', propertyType: 'date' }
		const wrapper = await mountTab([stored])
		wrapper.vm.startEdit(stored)
		await flushPromises()
		expect(wrapper.vm.editingId).toBe('pd-2')
		expect(wrapper.vm.editForm.propertyType).toBe('date')
		const hint = wrapper.find('.pd-fields__hint')
		expect(hint.exists()).toBe(true)
		expect(hint.text()).toContain('string')
		expect(hint.text()).toContain('date')
	})

	/**
	 * attribute-catalogue-folders, gap register row 11.23.
	 *
	 * @spec openspec/changes/attribute-catalogue-folders/specs/property-definition-management/spec.md
	 */
	describe('the picker groups the attributes by category', () => {
		const catalogue = () => [
			{ id: 'pd-1', name: 'Postcode', category: 'Address' },
			{ id: 'pd-2', name: 'Amount', category: 'Finance' },
			{ id: 'pd-3', name: 'House number', category: 'Address' },
			{ id: 'pd-4', name: 'Remark' },
		]

		it('heads each group with its category', async () => {
			const wrapper = await mountTab(catalogue())
			const headings = wrapper
				.findAll('.properties-tab__group')
				.map((node) => node.text())
			expect(headings).toEqual(['Address', 'Finance', 'Uncategorised'])
		})

		it('files an attribute with no category under Uncategorised', async () => {
			const wrapper = await mountTab(catalogue())
			const groups = wrapper.vm.groupedPropertyDefs
			const last = groups[groups.length - 1]
			expect(last.category).toBe('Uncategorised')
			expect(last.properties.map((pd) => pd.name)).toEqual(['Remark'])
		})

		it('folds the stored default into the same group as a missing one', async () => {
			// The schema default writes the English literal, so a row saved
			// today and a row authored before this change must not read as two
			// folders holding the same kind of nothing.
			const wrapper = await mountTab([
				{ id: 'pd-1', name: 'Remark' },
				{ id: 'pd-2', name: 'Note', category: 'Uncategorised' },
			])
			expect(wrapper.vm.groupedPropertyDefs).toHaveLength(1)
			expect(
				wrapper.vm.groupedPropertyDefs[0].properties.map((pd) => pd.name),
			).toEqual(['Remark', 'Note'])
		})

		it('lists every attribute exactly once, in its own group', async () => {
			const wrapper = await mountTab(catalogue())
			const groups = wrapper.vm.groupedPropertyDefs
			expect(groups.map((group) => group.category)).toEqual([
				'Address',
				'Finance',
				'Uncategorised',
			])
			expect(groups[0].properties.map((pd) => pd.name)).toEqual([
				'Postcode',
				'House number',
			])
			expect(groups.flatMap((group) => group.properties)).toHaveLength(4)
		})

		it('saves the category an author types', async () => {
			const wrapper = await mountTab([])
			wrapper.vm.applyNew({ name: 'Postcode', category: 'Address' })
			saveObject.mockResolvedValue({ id: 'pd-9', name: 'Postcode' })
			await wrapper.vm.addProperty()
			const saved = saveObject.mock.calls[0][1]
			expect(saved.category).toBe('Address')
		})
	})
})
