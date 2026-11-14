// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The attribute catalogue a case type picks its fields from (board
 * DqZaaktypeVelden, "Attribuut kiezen uit de catalogus").
 *
 * @spec openspec/specs/property-definition-management/spec.md
 */

import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FieldCatalogueDialog from '../../src/dialogs/FieldCatalogueDialog.vue'
import { buildCatalogue, catalogueEntry, copyForCaseType } from '../../src/services/fieldCatalogue.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

const ROOT = path.resolve(__dirname, '../..')

const ROWS = [
	{ id: 'a1', name: 'kenteken', definition: 'Kenteken', propertyType: 'string', category: 'Voertuig', caseType: 'ct-1', order: 3 },
	{ id: 'a2', name: 'kenteken', propertyType: 'string', category: 'Voertuig', caseType: 'ct-2' },
	{ id: 'a3', name: 'kenteken', propertyType: 'string', category: 'Voertuig', caseType: 'ct-3' },
	{ id: 'b1', name: 'bedrag', definition: 'Bedrag', propertyType: 'number', category: 'Financiën', caseType: '' },
	{ id: 'c1', name: 'opmerking', propertyType: 'string', caseType: 'ct-9', isRequired: true },
	{ id: 'x', name: '  ' },
]

describe('the catalogue of attributes', () => {
	it('lists one entry per name, counting the case types that carry it', () => {
		const { fields } = buildCatalogue(ROWS, 'ct-1')
		expect(fields.map((entry) => entry.name)).toEqual(['kenteken', 'bedrag', 'opmerking'])
		expect(fields.find((entry) => entry.name === 'kenteken').usageCount).toBe(3)
		expect(fields.find((entry) => entry.name === 'bedrag').usageCount).toBe(0)
	})

	it('files each entry in its category, and the folders are the categories in use', () => {
		const { fields, folders } = buildCatalogue(ROWS, 'ct-1')
		expect(folders).toEqual([{ id: 'Financiën', name: 'Financiën' }, { id: 'Voertuig', name: 'Voertuig' }])
		expect(fields.find((entry) => entry.name === 'opmerking').folderId).toBeUndefined()
	})

	it('does not offer again what the case type already carries', () => {
		expect(buildCatalogue(ROWS, 'ct-1').excludeIds).toEqual(['a1'])
		expect(buildCatalogue(ROWS, 'ct-new').excludeIds).toEqual([])
	})

	it('prefers the shared row of a name as the entry', () => {
		const entry = catalogueEntry([{ id: 'own', name: 'n', caseType: 'ct-1' }, { id: 'shared', name: 'n', caseType: '' }])
		expect(entry.id).toBe('shared')
	})

	it('copies an attribute for the case type, never its id, order or required flag', () => {
		expect(copyForCaseType(ROWS[4], 'ct-1')).toEqual({ caseType: 'ct-1', isRequired: false, name: 'opmerking', propertyType: 'string' })
		expect(copyForCaseType(ROWS[0], 'ct-7')).not.toHaveProperty('order')
	})
})

/**
 * Mount the dialog with the library catalogue stubbed.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog() {
	const axios = (await import('@nextcloud/axios')).default
	axios.get.mockResolvedValue({ data: { results: ROWS } })
	axios.post.mockResolvedValue({ data: { id: 'new' } })
	const wrapper = mount(FieldCatalogueDialog, {
		props: { caseTypeId: 'ct-1' },
		global: {
			stubs: { CnFieldCatalogueDialog: { name: 'CnFieldCatalogueDialog', props: ['fields', 'excludeIds', 'error'], template: '<div class="catalogue" />' } },
			mocks: { $route: { params: {} } },
		},
	})
	await new Promise((resolve) => setTimeout(resolve, 0))
	return wrapper
}

describe('FieldCatalogueDialog', () => {
	beforeEach(() => vi.clearAllMocks())

	it('hands the library dialog the catalogue of this case type', async () => {
		const wrapper = await mountDialog()
		const catalogue = wrapper.findComponent({ name: 'CnFieldCatalogueDialog' })
		expect(catalogue.props('fields')).toHaveLength(3)
		expect(catalogue.props('excludeIds')).toEqual(['a1'])
	})

	it('adds a copy of each picked attribute to the case type and closes', async () => {
		const wrapper = await mountDialog()
		const axios = (await import('@nextcloud/axios')).default
		const picked = wrapper.vm.fields.filter((entry) => entry.name !== 'kenteken')
		await wrapper.vm.add(picked)
		expect(axios.post).toHaveBeenCalledTimes(2)
		expect(axios.post.mock.calls[0][0]).toContain('/apps/openregister/api/objects/dossiq/propertyDefinition')
		expect(axios.post.mock.calls[0][1]).toMatchObject({ caseType: 'ct-1', name: 'bedrag' })
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('keeps OpenRegister\'s refusal in the dialog', async () => {
		const wrapper = await mountDialog()
		const axios = (await import('@nextcloud/axios')).default
		axios.post.mockRejectedValueOnce({ response: { data: { message: 'Not allowed to write propertyDefinition' } } })
		await wrapper.vm.add([wrapper.vm.fields[1]])
		expect(wrapper.vm.error).toBe('Not allowed to write propertyDefinition')
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})

describe('the Fields card opens the catalogue', () => {
	it('from its header, for this case type', () => {
		const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
		const page = manifest.pages.find((entry) => entry.id === 'CaseTypeDetail')
		const card = page.config.widgets.find((entry) => entry.id === 'case-type-fields-editor')
		expect(card.headerActions[0].action).toEqual({ type: 'open-modal', target: 'FieldCatalogueDialog', props: { caseTypeId: '@objectId' } })
		const registry = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
		expect(registry).toMatch(/FieldCatalogueDialog: \{\n\t\tkind: 'modal',/)
	})
})
