// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The term definitions tab (list, calendar, labels, saving a new version that
 * closes the old one) and the term definition editor (defaults, options and
 * validation), mounted with axios doubled.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const stub = (name) => ({ name, render: () => null })
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcDialog: stub('NcDialog'),
	NcEmptyContent: stub('NcEmptyContent'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcModal: stub('NcModal'),
	NcNoteCard: stub('NcNoteCard'),
	NcSelect: stub('NcSelect'),
	NcTextField: stub('NcTextField'),
}))

const { default: DeadlineDefinitionsTab } = await import('../../src/views/settings/tabs/DeadlineDefinitionsTab.vue')
const { default: DeadlineDefinitionEditor } = await import('../../src/modals/DeadlineDefinitionEditor.vue')

const D = '/index.php/apps/dossiq/api/deadlines'

/**
 * Mount a component and let it load.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}) {
	const w = shallowMount(component, { props })
	await flushPromises()
	return w
}

beforeEach(() => {
	vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
	for (const verb of ['get', 'post', 'patch']) {
		axios[verb].mockReset()
	}
	axios.get.mockImplementation(async (url) => (url.endsWith('/calendar')
		? { data: { timezone: 'Europe/Amsterdam', rollAvailable: true } }
		: { data: { results: [{ id: 'd1', caseType: 'Permit', validFrom: '2026-01-01' }, { id: 'd2', case_type: 'Permit' }, { id: 'd3', caseType: 'Bezwaar' }, { id: 'd4' }] } }))
})

afterEach(() => {
	vi.useRealTimers()
})

describe('DeadlineDefinitionsTab', () => {
	it('loads the definitions and the working calendar', async () => {
		const w = await mountIt(DeadlineDefinitionsTab)
		expect(w.vm.definitions).toHaveLength(4)
		expect([w.vm.timezone, w.vm.rollAvailable, w.vm.showCaseType]).toEqual(['Europe/Amsterdam', true, true])
		expect(w.vm.zaaktypeOptions).toEqual([{ id: 'Permit', label: 'Permit' }, { id: 'Bezwaar', label: 'Bezwaar' }])
		expect(w.vm.shownDefinitions).toHaveLength(4)
	})

	it('reads a bare list, and shows a failed load and an unknown calendar', async () => {
		axios.get.mockImplementation(async (url) => {
			if (url.endsWith('/calendar')) {
				throw new Error('x')
			}
			return { data: [{ id: 'd1' }] }
		})
		const w = await mountIt(DeadlineDefinitionsTab)
		expect([w.vm.definitions, w.vm.timezone, w.vm.rollAvailable, w.vm.showCaseType]).toEqual([[{ id: 'd1' }], '', null, false])
		axios.get.mockRejectedValueOnce({ response: { data: { message: 'No access' } } })
		await w.vm.load()
		expect([w.vm.error, w.vm.loading]).toEqual(['No access', false])
	})

	it('labels the counting mode, the duration, the basis and whether a version is in force', async () => {
		const w = await mountIt(DeadlineDefinitionsTab)
		expect([w.vm.countingModeLabel({ countingMode: 'workingDays' }), w.vm.countingModeLabel({})]).toEqual(['Working days', 'Calendar days'])
		expect([w.vm.formatDuur({ standardDurationWeeks: 8 }), w.vm.formatDuur({ duurDagen: 42 }), w.vm.formatDuur({ duur: 10 }), w.vm.formatDuur({})]).toEqual(['8 weeks', '42 days', '10 days', '—'])
		expect([w.vm.basisOf({ legalBasis: 'Awb 4:13' }), w.vm.basisOf({ basis: 'Wabo' }), w.vm.basisOf(null)]).toEqual(['Awb 4:13', 'Wabo', ''])
		expect([w.vm.isActive({}), w.vm.isActive({ validFrom: '2026-06-01' }), w.vm.isActive({ validUntil: '2026-05-14' }), w.vm.isActive({ validFrom: '2026-05-15', validUntil: '2026-05-15' })]).toEqual([true, false, false, true])
		expect([w.vm.rollsToWorkingDay({ rollToWorkingDay: true }), w.vm.rollsToWorkingDay({})]).toEqual([true, false])
	})

	it('opens the editor for a new or a next version, and closes it', async () => {
		const w = await mountIt(DeadlineDefinitionsTab)
		w.vm.openEdit({ id: 'd1' })
		expect([w.vm.editorOpen, w.vm.editingDefinition]).toEqual([true, { id: 'd1' }])
		w.vm.openNew()
		expect(w.vm.editingDefinition).toBeNull()
		w.vm.closeEditor()
		expect(w.vm.editorOpen).toBe(false)
	})

	it('saves a first version from tomorrow', async () => {
		const w = await mountIt(DeadlineDefinitionsTab)
		w.vm.openNew()
		await w.vm.onSave({ basis: 'Awb' })
		expect(axios.patch).not.toHaveBeenCalled()
		expect(axios.post).toHaveBeenCalledWith(`${D}/definitions`, { basis: 'Awb', validFrom: '2026-05-16', validUntil: null, version: 1 })
		expect(w.vm.editorOpen).toBe(false)
	})

	it('closes the current version today and saves the next one from tomorrow', async () => {
		const w = await mountIt(DeadlineDefinitionsTab)
		w.vm.openEdit({ id: 'd 1', version: '3' })
		await w.vm.onSave({ basis: 'Awb' })
		expect(axios.patch).toHaveBeenCalledWith(`${D}/definitions/d%201`, { validUntil: '2026-05-15' })
		expect(axios.post.mock.calls[0][1].version).toBe(4)
	})

	it('shows why a version could not be saved', async () => {
		const w = await mountIt(DeadlineDefinitionsTab)
		axios.post.mockRejectedValueOnce({ response: { data: { message: 'Overlapping version' } } })
		await w.vm.onSave({})
		expect(w.vm.error).toBe('Overlapping version')
		axios.post.mockRejectedValueOnce({})
		await w.vm.onSave({})
		expect(w.vm.error).toBe('Failed to save')
	})
})

describe('DeadlineDefinitionEditor', () => {
	it('starts a new definition with defaults and requires a case type, basis and duration', async () => {
		const w = await mountIt(DeadlineDefinitionEditor)
		expect([w.vm.title, w.vm.selectedZaaktype, w.vm.selectedCountingMode.id, w.vm.selectedCategorie.id]).toEqual(['New term definition', null, 'calendarDays', 'beslis'])
		await w.vm.save()
		expect(w.vm.errors).toEqual({ case_type: 'Zaaktype is required', basis: 'Wettelijke grondslag is required', duurDagen: 'Duration must be at least 1 day' })
		expect(w.emitted('save')).toBeUndefined()
	})

	it('starts a next version from the definition and saves the form', async () => {
		const w = await mountIt(DeadlineDefinitionEditor, { definition: { case_type: 'Permit', basis: 'Wabo', duur: 56, category: 'beroep', countingMode: 'workingDays' }, zaaktypeOptions: [{ id: 'Other', label: 'Other' }] })
		expect(w.vm.title).toBe('New version of Permit')
		expect([w.vm.selectedZaaktype, w.vm.selectedCountingMode.id, w.vm.selectedCategorie.id]).toEqual([{ id: 'Permit', label: 'Permit' }, 'workingDays', 'beroep'])
		await w.vm.save()
		expect(w.emitted('save')).toEqual([[{ case_type: 'Permit', basis: 'Wabo', duurDagen: 56, category: 'beroep', extendable: false, maxExtensionDagen: 0, countingMode: 'workingDays' }]])
		expect(w.vm.saving).toBe(false)
	})

	it('picks the named case type option and falls back on unknown choices', async () => {
		const w = await mountIt(DeadlineDefinitionEditor, { definition: { caseType: 'Bezwaar', case_type: 'b1', category: 'odd', countingMode: 'odd' }, zaaktypeOptions: [{ id: 'b1', label: 'Bezwaar' }] })
		expect([w.vm.title, w.vm.selectedZaaktype, w.vm.selectedCategorie.id, w.vm.selectedCountingMode.id]).toEqual(['New version of Bezwaar', { id: 'b1', label: 'Bezwaar' }, 'beslis', 'calendarDays'])
		expect(w.vm.categorieOptions).toHaveLength(4)
	})
})
