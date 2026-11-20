// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The dialog that answers a question on the case and publishes the answer:
 * it lists only open questions of this case, stores the answer, walks the
 * question's lifecycle (answer, publish) and keeps a refusal in the dialog.
 * Also checks that the tender case page offers it as a header action.
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/specs/dossiq-procurement-tender-management/spec.md#requirement-a-tender-is-worked-end-to-end-on-its-case-page-req-tnd-case-01
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AnswerPublishDialog from '../../src/dialogs/AnswerPublishDialog.vue'

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
const mockSuccess = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: (...a) => mockSuccess(...a) }))

const STUBS = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: { props: ['modelValue', 'options'], template: '<select />' },
	NcTextArea: { props: ['modelValue'], template: '<textarea :value="modelValue" />' },
	CommentCheckOutline: true,
}

const QUESTIONS = [
	{ id: 'q1', question: 'Is a site visit possible?', state: 'received', answer: '' },
	{ id: 'q2', question: 'May we bid on both lots?', state: 'answered', answer: 'Yes, on both.' },
	{ id: 'q3', question: 'Old question', state: 'published', answer: 'Done' },
	{ id: 'q4', question: 'Late question', state: 'rejected' },
]

/**
 * Mount the dialog and wait for its load.
 *
 * @param {object} props Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog(props = {}) {
	const wrapper = mount(AnswerPublishDialog, {
		props: { caseId: 'case-1', ...props },
		global: { stubs: STUBS },
	})
	await flushPromises()
	return wrapper
}

describe('AnswerPublishDialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		mockGet.mockResolvedValue({ data: { results: QUESTIONS } })
		mockPost.mockResolvedValue({ data: {} })
		mockPatch.mockResolvedValue({ data: { id: 'q1', state: 'published' } })
	})

	it('reads the questions of this case and offers only the open ones', async () => {
		const wrapper = await mountDialog()

		expect(mockGet).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/dossiq/tenderQuestion',
			{ params: { tender: 'case-1', _order: { submittedAt: 'asc' }, _limit: 100 } },
		)
		expect(wrapper.vm.questionOptions.map((o) => o.id)).toEqual(['q1', 'q2'])
		expect(wrapper.vm.questionId).toBe(null)
	})

	it('reads another schema by the case field the manifest names', async () => {
		await mountDialog({ register: 'r', schema: 'inquiry', caseField: 'case' })

		expect(mockGet).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/r/inquiry',
			{ params: { case: 'case-1', _order: { submittedAt: 'asc' }, _limit: 100 } },
		)
	})

	it('picks the only open question and starts from its stored answer', async () => {
		mockGet.mockResolvedValue({ data: { results: [QUESTIONS[1]] } })
		const wrapper = await mountDialog()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.questionId).toBe('q2')
		expect(wrapper.vm.answer).toBe('Yes, on both.')
	})

	it('says so when there is no open question', async () => {
		mockGet.mockResolvedValue({ data: { results: [QUESTIONS[2]] } })
		const wrapper = await mountDialog()

		expect(wrapper.find('[data-testid="answer-publish-empty"]').exists()).toBe(true)
		expect(wrapper.vm.canPublish).toBe(false)
	})

	it('cuts a long question to one line in the picker', async () => {
		const long = 'word '.repeat(40)
		mockGet.mockResolvedValue({ data: { results: [{ id: 'q9', question: long, state: 'received' }] } })
		const wrapper = await mountDialog()

		const label = wrapper.vm.questionOptions[0].label
		expect(label.length).toBe(90)
		expect(label.endsWith('…')).toBe(true)
	})

	it('shows a read failure', async () => {
		mockGet.mockRejectedValue(new Error('down'))
		const wrapper = await mountDialog()

		expect(wrapper.find('[data-testid="answer-publish-error"]').text())
			.toBe('The questions of this case could not be read.')
	})

	it('says it is publishing while the answer is on its way', async () => {
		const wrapper = await mountDialog()

		expect(wrapper.vm.submitLabel).toBe('Publish answer')
		wrapper.vm.saving = true
		expect(wrapper.vm.submitLabel).toBe('Publishing\u00a0…')
	})

	it('needs a question and an answer before it publishes', async () => {
		const wrapper = await mountDialog()
		wrapper.vm.questionId = 'q1'
		await wrapper.vm.$nextTick()
		wrapper.vm.answer = '   '

		expect(wrapper.vm.canPublish).toBe(false)
		await wrapper.vm.publish()
		expect(mockPatch).not.toHaveBeenCalled()
	})

	it('stores the answer, answers and publishes a received question, then stamps it', async () => {
		const wrapper = await mountDialog()
		wrapper.vm.questionId = 'q1'
		await wrapper.vm.$nextTick()
		wrapper.vm.answer = '  Yes, on 3 March.  '
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-testid="answer-publish-submit"]').trigger('click')
		await flushPromises()

		expect(mockPatch).toHaveBeenNthCalledWith(
			1,
			'/apps/openregister/api/objects/dossiq/tenderQuestion/q1',
			{ answer: 'Yes, on 3 March.' },
		)
		expect(mockPost.mock.calls).toEqual([
			['/apps/openregister/api/objects/q1/transition', { action: 'answer' }],
			['/apps/openregister/api/objects/q1/transition', { action: 'publish' }],
		])
		expect(mockPatch.mock.calls[1][1].publishedAt).toMatch(/^\d{4}-\d{2}-\d{2}T/)
		expect(mockSuccess).toHaveBeenCalledWith('Answer published.')
		expect(wrapper.emitted('done')).toEqual([[{ id: 'q1', state: 'published' }]])
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('only publishes a question that is answered already', async () => {
		const wrapper = await mountDialog()
		wrapper.vm.questionId = 'q2'
		await wrapper.vm.$nextTick()

		await wrapper.vm.publish()

		expect(mockPost.mock.calls).toEqual([
			['/apps/openregister/api/objects/q2/transition', { action: 'publish' }],
		])
	})

	it('keeps the server\'s refusal in the dialog and does not close', async () => {
		mockPost.mockRejectedValue({ response: { data: { message: 'Transition publish is not allowed from received.' } } })
		const wrapper = await mountDialog()
		wrapper.vm.questionId = 'q1'
		await wrapper.vm.$nextTick()
		wrapper.vm.answer = 'Yes'

		await wrapper.vm.publish()

		expect(wrapper.find('[data-testid="answer-publish-error"]').text())
			.toBe('Transition publish is not allowed from received.')
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(mockSuccess).not.toHaveBeenCalled()
		expect(wrapper.vm.saving).toBe(false)
	})

	it('falls back to its own sentence when the refusal carries none', async () => {
		mockPatch.mockRejectedValue(new Error('network'))
		const wrapper = await mountDialog()
		wrapper.vm.questionId = 'q1'
		await wrapper.vm.$nextTick()
		wrapper.vm.answer = 'Yes'

		await wrapper.vm.publish()

		expect(wrapper.vm.error).toBe('The answer could not be published.')
	})
})

describe('tender-answer-publish header action', () => {
	const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))

	/**
	 * Find the header action by id anywhere in the manifest.
	 *
	 * @param {*} node A manifest node.
	 * @return {object|null} The action.
	 */
	function findAction(node) {
		if (Array.isArray(node)) {
			for (const item of node) {
				const hit = findAction(item)
				if (hit) return hit
			}
			return null
		}
		if (node && typeof node === 'object') {
			if (node.id === 'tender-answer-publish') return node
			for (const value of Object.values(node)) {
				const hit = findAction(value)
				if (hit) return hit
			}
		}
		return null
	}

	it('opens the dialog for the case on a tender case type that is not archived', () => {
		const action = findAction(manifest)

		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('AnswerPublishDialog')
		expect(action.props).toMatchObject({ caseId: '@objectId', schema: 'tenderQuestion', caseField: 'tender' })
		expect(action.visibleWhen.all).toEqual([
			{ field: '@context.caseType.tenderChain', op: 'eq', value: true },
			{ field: '@self.archived', op: 'empty' },
		])
	})
})
