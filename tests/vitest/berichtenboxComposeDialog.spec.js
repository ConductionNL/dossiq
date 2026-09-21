// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The compose surface for a citizen's digital post, and the fact that
 * anything at all can open it.
 *
 * `BerichtenboxComposeDialog.vue` worked, and was referenced NOWHERE in
 * dossiq: not by `src/registry.js`, not by `src/manifest.json`, not by another
 * component. The only occurrence of its name in the repository was its own
 * `name:` line. So the dialog, the routing service and the stored message all
 * worked and there was no button to press that reached any of them.
 *
 * The other half of this file is the refusal. `BerichtenboxService` records a
 * refused letter as not sent, with integriq's own reason, and a dialog that
 * closed on it would put the reader back where they started: told that
 * something happened, with no way to learn what.
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

const mockGet = vi.fn()
const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))

/**
 * A stand-in for one @nextcloud/vue control.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function control(name) {
	return defineComponent({
		name,
		props: [
			'modelValue',
			'label',
			'error',
			'options',
			'trackBy',
			'ariaLabelCombobox',
			'type',
			'disabled',
			'size',
		],
		emits: ['update:modelValue', 'close'],
		render() {
			return h('div', { class: name }, this.$slots.default?.() ?? [])
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

const { default: BerichtenboxComposeDialog } =
	await import('../../src/dialogs/BerichtenboxComposeDialog.vue')

beforeEach(() => {
	mockGet.mockReset()
	mockPost.mockReset()
	mockGet.mockResolvedValue({ data: {} })
})

/**
 * Mount the dialog on a case, with a filled-in letter.
 *
 * @param {object} props Extra props.
 * @return {object} The wrapper.
 */
function open(props = {}) {
	const wrapper = mount(BerichtenboxComposeDialog, {
		props: { open: true, caseId: 'case-1', ...props },
		global: { mocks: { $route: { params: { id: 'case-from-route' } } } },
	})
	wrapper.vm.form.bsn = '123456782'
	wrapper.vm.form.subject = 'Besluit'
	wrapper.vm.form.body = 'De tekst'
	return wrapper
}

describe('a case can reach the compose dialog at all', () => {
	it('registers the dialog as a modal', () => {
		expect(registrySource).toContain('BerichtenboxComposeDialog: {')
		expect(registrySource).toContain(
			"import BerichtenboxComposeDialog from './dialogs/BerichtenboxComposeDialog.vue'",
		)
	})

	it('names it from a CaseDetail header action', () => {
		const page = manifest.pages.find((p) => p.id === 'CaseDetail')
		const action = page.config.headerActions.find(
			(a) => a.id === 'send-digital-post',
		)

		expect(action).toBeDefined()
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('BerichtenboxComposeDialog')
		// `open`, not `show`: every other registry modal on this page is
		// mounted with `open`, and an action declaring the other one would
		// mount a dialog that renders nothing at all.
		expect(action.props.open).toBe(true)
	})

	it('offers it only where a digital post box can exist', () => {
		const page = manifest.pages.find((p) => p.id === 'CaseDetail')
		const action = page.config.headerActions.find(
			(a) => a.id === 'send-digital-post',
		)

		// A company has a KvK number, not a BSN, and a letter cannot go to one.
		expect(action.visibleWhen).toEqual({
			field: 'initiatorType',
			op: 'eq',
			value: 'person',
		})
	})
})

describe('BerichtenboxComposeDialog', () => {
	it('falls back to the route when caseId is still the @objectId token', () => {
		const wrapper = open({ caseId: '@objectId' })

		expect(wrapper.vm.resolvedCaseId).toBe('case-from-route')
	})

	it('fills the recipient from a person case, and not from a company one', async () => {
		mockGet.mockResolvedValue({
			data: { initiatorType: 'person', initiatorSourceId: '123456782' },
		})
		const person = mount(BerichtenboxComposeDialog, {
			props: { open: true, caseId: 'case-1' },
		})
		await flushPromises()
		expect(person.vm.form.bsn).toBe('123456782')

		mockGet.mockResolvedValue({
			data: { initiatorType: 'company', initiatorSourceId: '69599084' },
		})
		const company = mount(BerichtenboxComposeDialog, {
			props: { open: true, caseId: 'case-2' },
		})
		await flushPromises()
		// A KvK number in the BSN field is an address a letter would go to.
		expect(company.vm.form.bsn).toBe('')
	})

	it('shows the provider’s own sentence and stays open on a refusal', async () => {
		mockPost.mockRejectedValue({
			response: {
				data: { error: 'No PKIoverheid certificate is configured.' },
			},
		})
		const wrapper = open()

		await wrapper.vm.send()
		await flushPromises()

		// THE SENTENCE, not a generic one: a handler told which credential is
		// missing can ask for it.
		expect(wrapper.vm.sendError).toBe(
			'No PKIoverheid certificate is configured.',
		)
		expect(wrapper.emitted('sent')).toBeUndefined()
	})

	it('treats a 200 carrying a refusal as a refusal, not as a send', async () => {
		// 🔴 THE BRANCH THAT WOULD OTHERWISE CLOSE OVER A LETTER NOBODY GOT.
		// The endpoint answers 400 for a refusal today, but the service records
		// the refused message and hands its record back, so a 200 carrying
		// `refused` is reachable the moment that status code is softened.
		mockPost.mockResolvedValue({
			data: {
				success: true,
				message: { refused: true, error: 'Integriq is not installed.' },
			},
		})
		const wrapper = open()

		await wrapper.vm.send()
		await flushPromises()

		expect(wrapper.vm.sendError).toBe('Integriq is not installed.')
		expect(wrapper.emitted('sent')).toBeUndefined()
	})

	it('emits sent for a letter integriq accepted', async () => {
		mockPost.mockResolvedValue({
			data: { success: true, message: { externalMessageId: 'dp-4711' } },
		})
		const wrapper = open()

		await wrapper.vm.send()
		await flushPromises()

		expect(wrapper.emitted('sent')).toHaveLength(1)
		expect(wrapper.vm.sendError).toBeNull()
	})

	it('refuses to send a letter with no body rather than posting one', async () => {
		const wrapper = open()
		wrapper.vm.form.body = ''

		await wrapper.vm.send()

		expect(mockPost).not.toHaveBeenCalled()
		expect(wrapper.vm.errors.body).toBeTruthy()
	})
})
