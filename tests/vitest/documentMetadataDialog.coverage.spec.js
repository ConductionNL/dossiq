// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DocumentMetadataDialog, the branches the behaviour specs do not reach: the
 * hash sentence, the two watchers that default the classification and the
 * title, the type-catalog fetch failing closed, the edit-mode record load
 * (no case, missing record, read failure), saveRecord refusing without a
 * record or id, and the upload loop's per-file progress, per-file failure and
 * all-failed outcome.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 * @spec openspec/specs/document-projection/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockGet = vi.fn()
const mockPost = vi.fn()
const mockPatch = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...a) => mockGet(...a),
		post: (...a) => mockPost(...a),
		patch: (...a) => mockPatch(...a),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
const mockShowError = vi.fn()
const mockShowSuccess = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({
	showError: (...a) => mockShowError(...a),
	showSuccess: (...a) => mockShowSuccess(...a),
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

/**
 * A form-control stub that keeps its v-model and forwards a click.
 *
 * @param {string} name The component name.
 * @return {object} The stub component.
 */
function control(name) {
	return defineComponent({
		name,
		props: [
			'modelValue', 'inputLabel', 'label', 'options', 'reduce', 'clearable',
			'required', 'placeholder', 'multiple', 'taggable', 'pushTags', 'size',
			'value', 'error', 'disabled', 'type',
		],
		emits: ['update:modelValue', 'click'],
		render() {
			return h('div', {
				class: name,
				onClick: () => this.$emit('click'),
			}, this.$slots.default ? this.$slots.default() : [])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcEmptyContent: control('NcEmptyContent'),
	NcModal: control('NcModal'),
	NcProgressBar: control('NcProgressBar'),
	NcSelect: control('NcSelect'),
	NcTextArea: control('NcTextArea'),
	NcTextField: control('NcTextField'),
}))

const { default: DocumentMetadataDialog } =
	await import('../../src/modals/DocumentMetadataDialog.vue')

beforeEach(() => {
	mockGet.mockReset()
	mockPost.mockReset()
	mockPatch.mockReset()
	mockShowError.mockReset()
	mockShowSuccess.mockReset()
	mockGet.mockResolvedValue({ data: { results: [] } })
	mockPost.mockResolvedValue({ data: {} })
	mockPatch.mockResolvedValue({ data: {} })
})

describe('DocumentMetadataDialog uncovered branches', () => {
	it('spells the hash with its algorithm, and says so when there is none', async () => {
		const wrapper = mount(DocumentMetadataDialog, { props: { files: [], caseId: 'case-1' } })
		await flushPromises()

		wrapper.vm.record = { integrity: { value: 'abc123', algorithm: 'sha512' } }
		expect(wrapper.vm.hashLabel).toBe('sha512: abc123')

		wrapper.vm.record = { integrity: { value: 'def456' } }
		expect(wrapper.vm.hashLabel).toBe('sha256: def456')

		wrapper.vm.record = { integrity: {} }
		expect(wrapper.vm.hashLabel).toBe('Not recorded')
	})

	it('defaults the classification from the type only while none is chosen', async () => {
		const wrapper = mount(DocumentMetadataDialog, { props: { files: [], caseId: 'case-1' } })
		await flushPromises()
		wrapper.vm.types = [{ id: 'iot-1', description: 'Advies', vertrouwelijkheidaanduiding: 'geheim' }]

		wrapper.vm.selectedType = 'iot-1'
		await flushPromises()
		expect(wrapper.vm.selectedClassification).toBe('geheim')

		// A second type does not overwrite a classification already set.
		wrapper.vm.types = [...wrapper.vm.types, { id: 'iot-2', vertrouwelijkheidaanduiding: 'intern' }]
		wrapper.vm.selectedType = 'iot-2'
		await flushPromises()
		expect(wrapper.vm.selectedClassification).toBe('geheim')
	})

	it('pre-fills the title from a single filename, but not from several', async () => {
		const wrapper = mount(DocumentMetadataDialog, { props: { files: [], caseId: 'case-1' } })
		await flushPromises()

		await wrapper.setProps({ files: [{ name: 'brief.pdf' }] })
		expect(wrapper.vm.title).toBe('brief.pdf')

		wrapper.vm.title = ''
		await wrapper.setProps({ files: [{ name: 'a.pdf' }, { name: 'b.pdf' }] })
		expect(wrapper.vm.title).toBe('')
	})

	it('fetches no types when the catalog read fails', async () => {
		mockGet.mockRejectedValueOnce(new Error('no catalog'))
		const wrapper = mount(DocumentMetadataDialog, { props: { files: [], caseId: 'case-1' } })
		await flushPromises()
		expect(wrapper.vm.types).toEqual([])
	})

	it('loads no record without a resolvable case id', async () => {
		const wrapper = mount(DocumentMetadataDialog, { props: { files: [], caseId: '@objectId', fileId: 5 } })
		await flushPromises()
		// The dossier endpoint is never asked when the case id is unresolved.
		expect(mockGet.mock.calls.some(([u]) => String(u).includes('/dossier'))).toBe(false)
		expect(wrapper.vm.record).toBe(null)
	})

	it('marks the record missing and seeds the title from the filename when the file has no record yet', async () => {
		mockGet.mockImplementation((url) => {
			if (String(url).includes('/dossier')) {
				return Promise.resolve({ data: { informatieobjecten: [{ fileId: 99 }] } })
			}
			return Promise.resolve({ data: { results: [] } })
		})
		const wrapper = mount(DocumentMetadataDialog, {
			props: { files: [], caseId: 'case-1', fileId: 5, fileName: 'besluit.pdf' },
		})
		await flushPromises()

		expect(wrapper.vm.record).toBe(null)
		expect(wrapper.vm.recordMissing).toBe(true)
		expect(wrapper.vm.title).toBe('besluit')
	})

	it('marks the record missing when the dossier read fails', async () => {
		mockGet.mockImplementation((url) => {
			if (String(url).includes('/dossier')) {
				return Promise.reject(new Error('boom'))
			}
			return Promise.resolve({ data: { results: [] } })
		})
		const wrapper = mount(DocumentMetadataDialog, {
			props: { files: [], caseId: 'case-1', fileId: 5 },
		})
		await flushPromises()
		expect(wrapper.vm.recordMissing).toBe(true)
	})

	it('refuses to save without a record, or with a record that has no id', async () => {
		const wrapper = mount(DocumentMetadataDialog, { props: { files: [], caseId: 'case-1' } })
		await flushPromises()

		wrapper.vm.record = null
		expect(await wrapper.vm.saveRecord({})).toBe(false)

		wrapper.vm.record = { '@self': {} }
		expect(await wrapper.vm.saveRecord({})).toBe(false)
		expect(mockPatch).not.toHaveBeenCalled()
	})

	it('reports per-file progress, counts a success, and uploads the rest when one file fails', async () => {
		let call = 0
		mockPost.mockImplementation((url, form, options) => {
			options.onUploadProgress({ total: 200, loaded: 100 })
			call += 1
			// The second file (bad.pdf) fails; the first succeeds.
			if (call === 2) {
				return Promise.reject(new Error('rejected'))
			}
			return Promise.resolve({ data: {} })
		})
		const wrapper = mount(DocumentMetadataDialog, {
			props: { files: [{ name: 'good.pdf' }, { name: 'bad.pdf' }], caseId: 'case-1' },
		})
		wrapper.vm.selectedType = 'iot-1'
		wrapper.vm.selectedClassification = 'intern'
		await flushPromises()

		await wrapper.vm.submit()

		expect(wrapper.vm.progress[0]).toBe(100)
		expect(wrapper.vm.errors[1]).toBe(true)
		expect(mockShowSuccess).toHaveBeenCalled()
		expect(wrapper.vm.uploading).toBe(false)
	})

	it('shows an upload failure when every file fails', async () => {
		mockPost.mockRejectedValue(new Error('all gone'))
		const wrapper = mount(DocumentMetadataDialog, {
			props: { files: [{ name: 'a.pdf' }], caseId: 'case-1' },
		})
		wrapper.vm.selectedType = 'iot-1'
		wrapper.vm.selectedClassification = 'intern'
		await flushPromises()

		await wrapper.vm.submit()

		expect(mockShowError).toHaveBeenCalledWith('Upload failed')
		expect(mockShowSuccess).not.toHaveBeenCalled()
	})
	it('reads no scan verdict for a file that has no id', async () => {
		const wrapper = mount(DocumentMetadataDialog, { props: { files: [], caseId: 'case-1' } })
		await flushPromises()
		mockGet.mockClear()

		wrapper.vm.scanVerdict = { value: 'stale' }
		await wrapper.vm.loadScanVerdict()

		expect(wrapper.vm.scanVerdict).toBe(null)
		expect(mockGet.mock.calls.some(([u]) => String(u).includes('/scan'))).toBe(false)
	})

	it('reports a failed PATCH as not saved', async () => {
		const wrapper = mount(DocumentMetadataDialog, { props: { files: [], caseId: 'case-1' } })
		await flushPromises()

		wrapper.vm.record = { id: 'r-1' }
		mockPatch.mockRejectedValueOnce(new Error('conflict'))
		expect(await wrapper.vm.saveRecord({ title: 'x' })).toBe(false)
		expect(mockPatch).toHaveBeenCalled()
	})
})
