// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Generate document: the header action, and the dialog it opens.
 *
 * Two silent failure modes are pinned here. An `open-modal` action resolves
 * its target against the component registry and refuses anything that is not
 * `kind: "modal"` — with a console warning and no dialog. And the dispatcher
 * forwards `action.props` VERBATIM, resolving no `@`-tokens, so a dialog that
 * trusted `caseId` would POST to `/api/cases/%40objectId/...` and report a
 * generic failure.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 * @spec openspec/specs/template-library/spec.md
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

/**
 * A stub for one @nextcloud/vue component.
 *
 * @param {string} name The component name.
 * @return {object} The stub component.
 */
function stub(name) {
	return defineComponent({
		name,
		props: [
			'modelValue',
			'inputLabel',
			'label',
			'options',
			'reduce',
			'placeholder',
			'name',
			'size',
			'canClose',
			'type',
			'disabled',
		],
		emits: ['update:modelValue', 'click', 'closing'],
		render() {
			return h('div', { class: name, onClick: () => this.$emit('click') }, [
				this.$slots.default ? this.$slots.default() : null,
				this.$slots.actions ? this.$slots.actions() : null,
			])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcDialog: stub('NcDialog'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
	NcSelect: stub('NcSelect'),
}))

// Imported AFTER the mock so the dialog sees the stubbed package.
const { default: BeschikkingComposerDialog } =
	await import('../../src/dialogs/BeschikkingComposerDialog.vue')

const MANIFEST_PATH = path.resolve(__dirname, '../../src/manifest.json')
const REGISTRY_PATH = path.resolve(__dirname, '../../src/registry.js')
const ICONS_PATH = path.resolve(__dirname, '../../src/icons.js')

const manifest = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))
const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
const action = caseDetail.config.headerActions.find(
	(entry) => entry.id === 'generate-document',
)

/**
 * Mount the dialog over a stubbed library.
 *
 * @param {object} options Overrides for the mount.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountDialog({ caseId = '@objectId', routeId = 'case-1' } = {}) {
	axios.get.mockResolvedValue({
		data: {
			results: [
				{ id: 'ontvangstbevestiging', title: 'Ontvangstbevestiging' },
				{ id: 'verdagingsbrief', title: 'Verdagingsbrief' },
			],
		},
	})
	axios.post.mockResolvedValue({ data: { informatieobject: 'inf-1' } })

	const wrapper = mount(BeschikkingComposerDialog, {
		props: { open: true, caseId },
		global: { mocks: { $route: { params: { id: routeId } } } },
	})
	await flushPromises()
	return wrapper
}

describe('the Generate document header action', () => {
	it('is declared on CaseDetail', () => {
		expect(
			action,
			'CaseDetail must carry the generate-document action',
		).toBeTruthy()
		expect(action.label).toBe('Generate document')
	})

	it('opens a target the registry answers to as a modal', () => {
		const registry = fs.readFileSync(REGISTRY_PATH, 'utf8')
		const entry = registry.slice(registry.indexOf(`\t${action.target}: {`))

		expect(action.type).toBe('open-modal')
		expect(entry.slice(0, 200)).toContain("kind: 'modal'")
	})

	it('names an icon src/icons.js registers', () => {
		expect(fs.readFileSync(ICONS_PATH, 'utf8')).toContain(`\t${action.icon},`)
	})

	it('says what happened when it succeeds', () => {
		expect(action.successMessage).toBe('Document added to the case.')
	})

	it('passes open, because a registry modal renders on that prop', () => {
		expect(action.props.open).toBe(true)
	})
})

describe('BeschikkingComposerDialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('lists the library templates by name', async () => {
		const wrapper = await mountDialog()

		expect(axios.get).toHaveBeenCalledWith(
			expect.stringContaining('/apps/dossiq/api/templates'),
		)
		expect(wrapper.vm.templateOptions.map((option) => option.label)).toEqual([
			'Ontvangstbevestiging',
			'Verdagingsbrief',
		])
	})

	it('ignores an unresolved @objectId prop and uses the route', async () => {
		const wrapper = await mountDialog({ caseId: '@objectId', routeId: 'case-7' })

		expect(wrapper.vm.resolvedCaseId).toBe('case-7')
	})

	it('uses a real caseId prop when the dispatcher resolves one', async () => {
		const wrapper = await mountDialog({ caseId: 'case-42', routeId: 'case-7' })

		expect(wrapper.vm.resolvedCaseId).toBe('case-42')
	})

	it('files the chosen template on the case', async () => {
		const wrapper = await mountDialog({ routeId: 'case-7' })
		await wrapper.setData({ templateId: 'ontvangstbevestiging' })

		await wrapper.vm.onGenerate()

		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining(
				'/apps/dossiq/api/cases/case-7/dossier/generate',
			),
			{ templateId: 'ontvangstbevestiging' },
		)
		expect(wrapper.emitted('generated')).toBeTruthy()
	})

	it('generates nothing until a template is picked', async () => {
		const wrapper = await mountDialog()

		await wrapper.vm.onGenerate()

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('says so when the generation fails, and stays open', async () => {
		const wrapper = await mountDialog()
		await wrapper.setData({ templateId: 'ontvangstbevestiging' })
		axios.post.mockRejectedValueOnce(new Error('400'))

		await wrapper.vm.onGenerate()

		expect(wrapper.vm.error).toBe('The document could not be generated.')
		expect(wrapper.vm.generated).toBeNull()
	})
})
