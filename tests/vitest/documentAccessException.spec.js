// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Who can see this card (board DqDocumentenBeheer): the exception and its reason on the card, Change exception
 * offered only where the row says the caller may change it, and the dialog that sets and removes it.
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-the-who-can-see-this-card-shows-the-exception-and-changes-it
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'node:fs'
import path from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockGet = vi.fn()
const mockPut = vi.fn()
const mockDelete = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...a) => mockGet(...a),
		put: (...a) => mockPut(...a),
		delete: (...a) => mockDelete(...a),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) => String(url).replace(/\{(\w+)\}/g, (_, key) => String((params && params[key]) ?? `{${key}}`)),
	generateOcsUrl: (url) => `/ocs/v2.php/${url}`,
}))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
const mockEmit = vi.fn()
vi.mock('@nextcloud/event-bus', () => ({ emit: (...a) => mockEmit(...a) }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => String(text).replace(/\{(\w+)\}/g, (_, key) => String((vars && vars[key]) ?? `{${key}}`)),
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))

/**
 * A stand-in for an @nextcloud/vue control.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
function control(name) {
	return defineComponent({
		name,
		props: ['modelValue', 'inputLabel', 'label', 'options', 'multiple', 'loading', 'filterBy', 'disabled', 'name', 'type', 'variant', 'noClose'],
		emits: ['update:modelValue', 'closing', 'search', 'click'],
		render() {
			return h('div', { class: name }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	})
}
vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcDialog: control('NcDialog'),
	NcNoteCard: control('NcNoteCard'),
	NcSelect: control('NcSelect'),
	NcTextField: control('NcTextField'),
}))
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => ({}) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

const { default: Dialog } = await import('../../src/dialogs/DocumentAccessExceptionDialog.vue')
const { default: formatters } = await import('../../src/services/formatters.js')

const ROW = {
	id: 'doc-offerte',
	fileId: 42,
	accessException: { users: ['pieter'], groups: ['woo-team'], reason: 'Bedrijfsgevoelige prijzen' },
	accessExceptionNames: ['Pieter Jansen', 'Woo-team'],
	canChangeAccess: true,
}

/**
 * Mount the dialog on the board's document.
 *
 * @param {object} row The listing row behind file 42.
 * @return {Promise<object>} The wrapper.
 */
async function open(row = ROW) {
	mockGet.mockResolvedValue({ data: { informatieobjecten: [row, { id: 'doc-verzoek', fileId: 43 }] } })
	const wrapper = mount(Dialog, {
		props: { fileId: 42, fileName: 'Offerte verlichting.pdf', caseId: 'case-0082' },
		global: { mocks: { t: (app, text, vars) => String(text).replace(/\{(\w+)\}/g, (_, key) => String((vars && vars[key]) ?? '')) } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	mockGet.mockReset()
	mockPut.mockReset().mockResolvedValue({ data: {} })
	mockDelete.mockReset().mockResolvedValue({ data: { removed: true } })
	mockEmit.mockReset()
})

describe('the Who can see this card', () => {
	const manifest = JSON.parse(fs.readFileSync(path.join(__dirname, '../../src/manifest.json'), 'utf8'))
	const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
	const files = caseDetail.config.widgets.find((widget) => widget.id === 'case-files')
	const card = files.props.sideCards.find((c) => c.id === 'visibility')

	it('shows the default, the exception and the reason, as the board draws', () => {
		expect(card.fields.map((field) => [field.key, field.label])).toEqual([
			['vertrouwelijkheidaanduiding', 'Default'],
			['accessExceptionNames', 'Exception'],
			['accessException.reason', 'Reason'],
		])
		expect(formatters.accessExceptionNames(ROW.accessExceptionNames)).toBe('Only Pieter Jansen and Woo-team')
		expect(formatters.accessExceptionNames(['Pieter Jansen'])).toBe('Only Pieter Jansen')
		expect(formatters.accessExceptionNames(['A', 'B', 'C'])).toBe('Only A, B and C')
		expect(formatters.accessExceptionNames(undefined)).toBe('')
	})

	it('offers Change exception only on a row whose canChangeAccess is true', () => {
		expect(card.actions).toHaveLength(1)
		expect(card.actions[0]).toMatchObject({
			id: 'change-access-exception',
			label: 'Change exception',
			type: 'open-modal',
			target: 'DocumentAccessExceptionDialog',
			visibleIf: { row: { canChangeAccess: true } },
		})
	})
})

describe('DocumentAccessExceptionDialog', () => {
	it('opens on the exception the document carries now', async () => {
		const wrapper = await open()
		expect(mockGet).toHaveBeenCalledWith('/apps/dossiq/api/cases/case-0082/dossier')
		expect(wrapper.vm.documentId).toBe('doc-offerte')
		expect(wrapper.vm.principals.map((option) => [option.id, option.label])).toEqual([
			['user:pieter', 'Pieter Jansen'],
			['group:woo-team', 'Woo-team'],
		])
		expect(wrapper.vm.reason).toBe('Bedrijfsgevoelige prijzen')
		expect(wrapper.find('[data-testid="document-access-exception-remove"]').exists()).toBe(true)
	})

	it('saves the people, groups and reason to the document', async () => {
		const wrapper = await open()
		await wrapper.vm.onSave()
		await flushPromises()
		expect(mockPut).toHaveBeenCalledWith('/apps/dossiq/api/informatieobjecten/doc-offerte/access-exception', {
			users: ['pieter'],
			groups: ['woo-team'],
			reason: 'Bedrijfsgevoelige prijzen',
		})
		expect(mockEmit).toHaveBeenCalledWith('cn:page:refresh')
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('cannot save without someone named or without a reason', async () => {
		const wrapper = await open({ id: 'doc-offerte', fileId: 42 })
		expect(wrapper.vm.canSave).toBe(false)
		expect(wrapper.find('[data-testid="document-access-exception-remove"]').exists()).toBe(false)
		wrapper.vm.principals = [wrapper.vm.option(0, 'pieter', 'Pieter Jansen')]
		expect(wrapper.vm.canSave).toBe(false)
		wrapper.vm.reason = 'Prijzen'
		expect(wrapper.vm.canSave).toBe(true)
	})

	it('removes the exception', async () => {
		const wrapper = await open()
		await wrapper.vm.onRemove()
		await flushPromises()
		expect(mockDelete).toHaveBeenCalledWith('/apps/dossiq/api/informatieobjecten/doc-offerte/access-exception', { data: { reason: 'Bedrijfsgevoelige prijzen' } })
	})

	it('shows the server\'s sentence when it refuses, and stays open', async () => {
		mockPut.mockRejectedValue({ response: { data: { error: 'You may not change a case this document belongs to; nothing was changed' } } })
		const wrapper = await open()
		await wrapper.vm.onSave()
		await flushPromises()
		expect(wrapper.vm.error).toBe('You may not change a case this document belongs to; nothing was changed')
		expect(wrapper.emitted('close')).toBeFalsy()
	})

	it('searches people and groups through the sharee search', async () => {
		const wrapper = await open()
		mockGet.mockResolvedValue({
			data: { ocs: { data: { exact: { users: [], groups: [] }, users: [{ label: 'Anna de Vries', value: { shareType: 0, shareWith: 'anna' } }], groups: [{ label: 'Juristen', value: { shareType: 1, shareWith: 'juristen' } }] } } },
		})
		await wrapper.vm.onSearch('an')
		expect(mockGet).toHaveBeenLastCalledWith('/ocs/v2.php/apps/files_sharing/api/v1/sharees', expect.objectContaining({ params: expect.objectContaining({ search: 'an', shareType: [0, 1] }) }))
		expect(wrapper.vm.found.map((option) => option.id)).toEqual(['user:anna', 'group:juristen'])
	})
})
