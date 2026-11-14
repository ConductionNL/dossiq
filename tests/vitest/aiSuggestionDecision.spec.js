// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a person does with one AI suggestion: use it, adapt it and use that,
 * or reject it. The component reports the decision and copies what is used.
 *
 * @spec openspec/specs/ai-oversight-dispatch/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@nextcloud/vue', () => ({
	NcButton: defineComponent({
		name: 'NcButton',
		props: ['variant'],
		emits: ['click'],
		render() {
			return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	}),
}))

const { default: AiSuggestionDecision } = await import('../../src/components/case/AiSuggestionDecision.vue')

let writeText

beforeEach(() => {
	writeText = vi.fn().mockResolvedValue(undefined)
	Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
})

afterEach(() => {
	vi.restoreAllMocks()
})

/**
 * Mount the decision for a suggestion.
 *
 * @param {object} props Extra props.
 * @return {object} The wrapper.
 */
function mountDecision(props = {}) {
	return mount(AiSuggestionDecision, { props: { text: 'Suggested reply', ...props } })
}

/**
 * Click the element with this test id.
 *
 * @param {object} w The wrapper.
 * @param {string} id The test id.
 * @return {Promise<void>}
 */
async function click(w, id) {
	await w.find(`[data-testid="${id}"]`).trigger('click')
	await flushPromises()
}

describe('AiSuggestionDecision', () => {
	it('offers use, adapt and reject while undecided', () => {
		const w = mountDecision()

		expect(w.find('[data-testid="ai-suggestion-decision-use"]').exists()).toBe(true)
		expect(w.find('[data-testid="ai-suggestion-decision-adapt"]').exists()).toBe(true)
		expect(w.find('[data-testid="ai-suggestion-decision-reject"]').exists()).toBe(true)
		expect(w.find('[data-testid="ai-suggestion-decision-draft"]').exists()).toBe(false)
	})

	it('using the suggestion reports accepted without a value and copies the text', async () => {
		const w = mountDecision()

		await click(w, 'ai-suggestion-decision-use')

		expect(w.emitted('decide')).toEqual([[{ userAction: 'accepted', actualValue: undefined }]])
		expect(writeText).toHaveBeenCalledWith('Suggested reply')
		expect(w.find('[data-testid="ai-suggestion-decision-done"]').text()).toBe('Used. Copied to your clipboard.')
		expect(w.find('[data-testid="ai-suggestion-decision-use"]').exists()).toBe(false)
	})

	it('adapting starts from the suggestion and reports the edited version', async () => {
		const w = mountDecision()

		await click(w, 'ai-suggestion-decision-adapt')
		const draft = w.find('[data-testid="ai-suggestion-decision-draft"]')
		expect(draft.element.value).toBe('Suggested reply')
		expect(w.find('[data-testid="ai-suggestion-decision-use"]').exists()).toBe(false)

		await draft.setValue('My own reply')
		await click(w, 'ai-suggestion-decision-use-adapted')

		expect(w.emitted('decide')).toEqual([[{ userAction: 'modified', actualValue: 'My own reply' }]])
		expect(writeText).toHaveBeenCalledWith('My own reply')
		expect(w.find('[data-testid="ai-suggestion-decision-done"]').text()).toBe('Your version is used. Copied to your clipboard.')
	})

	it('rejecting reports rejected and copies nothing', async () => {
		const w = mountDecision()

		await click(w, 'ai-suggestion-decision-reject')

		expect(w.emitted('decide')).toEqual([[{ userAction: 'rejected', actualValue: undefined }]])
		expect(writeText).not.toHaveBeenCalled()
		expect(w.find('[data-testid="ai-suggestion-decision-done"]').text()).toBe('Rejected.')
	})

	it('a refused clipboard still records the decision, without promising a copy', async () => {
		writeText.mockRejectedValue(new Error('denied'))
		const used = mountDecision()
		await click(used, 'ai-suggestion-decision-use')
		expect(used.find('[data-testid="ai-suggestion-decision-done"]').text()).toBe('Used.')

		const adapted = mountDecision()
		await click(adapted, 'ai-suggestion-decision-adapt')
		await click(adapted, 'ai-suggestion-decision-use-adapted')
		expect(adapted.find('[data-testid="ai-suggestion-decision-done"]').text()).toBe('Your version is used.')
		expect(adapted.emitted('decide')).toHaveLength(1)
	})

	it('a new suggestion starts undecided again', async () => {
		const w = mountDecision()
		await click(w, 'ai-suggestion-decision-adapt')
		await w.find('[data-testid="ai-suggestion-decision-draft"]').setValue('Draft')
		await click(w, 'ai-suggestion-decision-reject')

		await w.setProps({ text: 'Another suggestion' })

		expect(w.find('[data-testid="ai-suggestion-decision-done"]').exists()).toBe(false)
		expect(w.find('[data-testid="ai-suggestion-decision-use"]').exists()).toBe(true)
		expect(w.find('[data-testid="ai-suggestion-decision-draft"]').exists()).toBe(false)
	})

	it('prefixes every test id with the given one', async () => {
		const w = mountDecision({ testid: 'sug-1' })

		expect(w.attributes('data-testid')).toBe('sug-1')
		await click(w, 'sug-1-use')
		expect(w.find('[data-testid="sug-1-done"]').exists()).toBe(true)
	})
})
