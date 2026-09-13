// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The upload dialog carries the keywords and the direction with the file,
 * and performs the upload itself.
 *
 * The dialog is the only place a keyword or a direction is ever typed, and
 * what it sends is what the upload endpoint stores. A field that renders but
 * is left out of the sent metadata looks exactly like a field that works:
 * the upload succeeds and the tag is simply gone.
 *
 * Self-sufficient (documents-on-the-case task 2.2, the CnObjectListWidget
 * swap): the dialog used to emit `submit` with the metadata and let a parent
 * DossierTab perform the axios POST; there is no such parent once it opens
 * as a manifest `open-modal` action, so it fetches the type catalog and
 * performs the upload itself. `types` is no longer a prop for that reason —
 * tests set it directly as data, the way the dialog's own `fetchTypes()`
 * would.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 * @spec openspec/specs/document-zaakdossier/spec.md
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
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

/**
 * A stub for one @nextcloud/vue form control that keeps its v-model.
 *
 * @param {string} name The component name.
 * @return {object} The stub component.
 */
function control(name) {
	return defineComponent({
		name,
		props: [
			'modelValue',
			'inputLabel',
			'label',
			'options',
			'reduce',
			'clearable',
			'required',
			'placeholder',
			'multiple',
			'taggable',
			'pushTags',
			'size',
			'value',
			'error',
			'disabled',
			'type',
		],
		emits: ['update:modelValue', 'click'],
		render() {
			return h(
				'div',
				{
					class: name,
					'data-label': this.inputLabel || this.label,
					onClick: () => this.$emit('click'),
				},
				this.$slots.default ? this.$slots.default() : [],
			)
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcModal: control('NcModal'),
	NcProgressBar: control('NcProgressBar'),
	NcSelect: control('NcSelect'),
	NcTextArea: control('NcTextArea'),
	NcTextField: control('NcTextField'),
}))

// Imported AFTER the mocks so the dialog sees the stubbed packages.
const { default: DocumentMetadataDialog } =
	await import('../../src/modals/DocumentMetadataDialog.vue')

/**
 * Mount the dialog with one pending file and a resolvable case id.
 *
 * @return {object} The mounted wrapper.
 */
function mountDialog() {
	return mount(DocumentMetadataDialog, {
		props: {
			files: [{ name: 'bezwaarschrift.pdf' }],
			caseId: 'case-1',
		},
	})
}

/**
 * The metadata one submit sent.
 *
 * @param {object} wrapper The mounted dialog.
 * @return {object} The metadata carried on the upload POST body.
 */
function postedMetadata() {
	expect(mockPost, 'the dialog must POST the upload').toHaveBeenCalled()
	const [, form] = mockPost.mock.calls[mockPost.mock.calls.length - 1]
	return JSON.parse(form.get('metadata'))
}

beforeEach(() => {
	mockGet.mockReset()
	mockPost.mockReset()
	mockPatch.mockReset()
	mockPatch.mockResolvedValue({ data: {} })
	mockGet.mockResolvedValue({
		data: { results: [{ id: 'iot-1', description: 'Advies' }] },
	})
	mockPost.mockResolvedValue({ data: {} })
})

describe('DocumentMetadataDialog', () => {
	it('carries the typed keywords with the upload', async () => {
		const wrapper = mountDialog()
		await flushPromises()
		await wrapper.setData({
			selectedType: 'iot-1',
			selectedClassification: 'openbaar',
			keywords: ['bezwaar', 'bouwtekening'],
		})

		await wrapper.vm.submit()
		expect(postedMetadata().keywords).toEqual(['bezwaar', 'bouwtekening'])
	})

	it('sends keywords as plain strings, not as picker options', async () => {
		const wrapper = mountDialog()
		await flushPromises()
		await wrapper.setData({
			selectedType: 'iot-1',
			selectedClassification: 'openbaar',
			keywords: [{ label: 'bezwaar' }, ' bouwtekening ', 'bezwaar', ''],
		})

		await wrapper.vm.submit()
		expect(postedMetadata().keywords).toEqual(['bezwaar', 'bouwtekening'])
	})

	it('truncates a keyword to the 64 characters the schema allows', async () => {
		const wrapper = mountDialog()
		await flushPromises()
		await wrapper.setData({
			selectedType: 'iot-1',
			selectedClassification: 'openbaar',
			keywords: ['a'.repeat(80)],
		})

		await wrapper.vm.submit()
		expect(postedMetadata().keywords).toEqual(['a'.repeat(64)])
	})

	it('carries the chosen direction with the upload', async () => {
		const wrapper = mountDialog()
		await flushPromises()
		await wrapper.setData({
			selectedType: 'iot-1',
			selectedClassification: 'openbaar',
			selectedDirection: 'incoming',
		})

		await wrapper.vm.submit()
		expect(postedMetadata().direction).toBe('incoming')
	})

	it('defaults the direction to internal', async () => {
		const wrapper = mountDialog()
		await flushPromises()
		await wrapper.setData({
			selectedType: 'iot-1',
			selectedClassification: 'openbaar',
		})

		expect(wrapper.vm.selectedDirection).toBe('internal')
		await wrapper.vm.submit()
		const metadata = postedMetadata()
		expect(metadata.direction).toBe('internal')
		expect(metadata.keywords).toEqual([])
	})

	it('offers the three directions the schema enumerates', async () => {
		const wrapper = mountDialog()
		await flushPromises()

		expect(wrapper.vm.directionOptions.map((option) => option.id)).toEqual([
			'incoming',
			'outgoing',
			'internal',
		])
		expect(wrapper.vm.directionOptions.map((option) => option.label)).toEqual([
			'Incoming',
			'Outgoing',
			'Internal',
		])
	})

	it('labels both new controls, so a screen reader can name them', async () => {
		const wrapper = mountDialog()
		await flushPromises()
		const labels = wrapper
			.findAll('.NcSelect')
			.map((select) => select.attributes('data-label'))

		expect(labels).toContain('Direction')
		expect(labels).toContain('Keywords')
	})

	it('keeps the required type and confidentiality gate', async () => {
		const wrapper = mountDialog()
		await flushPromises()

		expect(wrapper.vm.canSubmit).toBe(false)
		await wrapper.vm.submit()
		expect(mockPost).not.toHaveBeenCalled()
		expect(wrapper.emitted('submit')).toBeUndefined()
	})

	it('fetches the type catalog itself once the dialog opens', async () => {
		mountDialog()
		await flushPromises()

		expect(mockGet).toHaveBeenCalledWith(
			expect.stringContaining('informatieobjecttype'),
		)
	})

	it('resolves the case id from the prop, falling back to the route when it still holds the @objectId token', async () => {
		const wrapper = mount(DocumentMetadataDialog, {
			props: { open: true, files: [{ name: 'a.pdf' }], caseId: '@objectId' },
			global: { mocks: { $route: { params: { id: 'route-case' } } } },
		})
		await flushPromises()
		await wrapper.setData({
			selectedType: 'iot-1',
			selectedClassification: 'openbaar',
		})

		await wrapper.vm.submit()

		expect(mockPost.mock.calls[0][0]).toContain('route-case')
	})

	// documents-live-on-the-case: the same dialog, opened on a file's record
	// from the Files tab's Document properties action.
	describe('opened on an existing file', () => {
		/**
		 * Mount the dialog on file 12 of case-1.
		 *
		 * @return {object} The mounted wrapper.
		 */
		function mountOnFile() {
			return mount(DocumentMetadataDialog, {
				props: {
						caseId: 'case-1',
					fileId: 12,
					fileName: 'aanvraag.pdf',
				},
			})
		}

		it('loads the record the file has and saves it with a PATCH, not an upload', async () => {
			mockGet.mockImplementation(async (url) => {
				if (url.includes('/dossier')) {
					return {
						data: {
							informatieobjecten: [
								{ id: 'inf-9', fileId: 99, title: 'other' },
								{
									id: 'inf-1',
									fileId: 12,
									title: 'Aanvraag',
									informatieobjecttype: 'iot-1',
									vertrouwelijkheidaanduiding: 'openbaar',
									direction: 'incoming',
									keywords: ['bouw'],
									description: 'Het formulier',
								},
							],
						},
					}
				}
				return {
					data: { results: [{ id: 'iot-1', description: 'Advies' }] },
				}
			})
			const wrapper = mountOnFile()
			await flushPromises()

			expect(
				wrapper.get('[data-testid="document-properties-file"]').text(),
			).toBe('aanvraag.pdf')
			expect(wrapper.vm.title).toBe('Aanvraag')
			expect(wrapper.vm.selectedType).toBe('iot-1')
			expect(wrapper.vm.selectedClassification).toBe('openbaar')
			expect(wrapper.vm.keywords).toEqual(['bouw'])
			expect(
				wrapper.find('[data-testid="document-properties-missing"]').exists(),
			).toBe(false)

			await wrapper.setData({ title: 'Aanvraagformulier, herzien' })
			await wrapper.vm.submit()

			expect(mockPost).not.toHaveBeenCalled()
			expect(mockPatch).toHaveBeenCalledTimes(1)
			const [url, body] = mockPatch.mock.calls[0]
			expect(url).toBe('/apps/dossiq/api/informatieobjecten/inf-1')
			expect(body).toMatchObject({
				title: 'Aanvraagformulier, herzien',
				informatieobjecttype: 'iot-1',
				vertrouwelijkheidaanduiding: 'openbaar',
				keywords: ['bouw'],
				description: 'Het formulier',
			})
			expect(wrapper.emitted('close')).toBeTruthy()
		})

		it('renders when mounted with only the row action props, as the registry mounts it', async () => {
			// The manifest's open-modal path hands the component fileId,
			// fileName and path and nothing else; measured 2026-09-13 on the
			// case page, a false `open` default left the modal unrendered
			// with no warning anywhere.
			mockGet.mockImplementation(async (url) => {
				if (url.includes('/dossier')) {
					return {
						data: {
							informatieobjecten: [
								{ id: 'inf-1', fileId: 12, title: 'Aanvraag' },
							],
						},
					}
				}
				return {
					data: { results: [{ id: 'iot-1', description: 'Advies' }] },
				}
			})
			const wrapper = mount(DocumentMetadataDialog, {
				props: { caseId: 'case-1', fileId: 12, fileName: 'aanvraag.pdf' },
			})
			await flushPromises()

			expect(
				wrapper.get('[data-testid="document-properties-file"]').text(),
			).toBe('aanvraag.pdf')
			expect(wrapper.vm.title).toBe('Aanvraag')
		})

		it('says so when the file has no record yet, and does not pretend to save', async () => {
			mockGet.mockImplementation(async (url) => {
				if (url.includes('/dossier')) {
					return { data: { informatieobjecten: [] } }
				}
				return {
					data: { results: [{ id: 'iot-1', description: 'Advies' }] },
				}
			})
			const wrapper = mountOnFile()
			await flushPromises()

			expect(
				wrapper.get('[data-testid="document-properties-missing"]').exists(),
			).toBe(true)
			expect(wrapper.vm.title).toBe(
				'aanvraag',
				'the title starts from the file name',
			)

			await wrapper.setData({
				selectedType: 'iot-1',
				selectedClassification: 'openbaar',
			})
			await wrapper.vm.submit()
			expect(mockPatch).not.toHaveBeenCalled()
			expect(mockPost).not.toHaveBeenCalled()
			expect(wrapper.emitted('close')).toBeFalsy()
		})
	})
})
