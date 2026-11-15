// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The redaction assist dialog rendered and driven through its template: typing
 * the text, the detect button and its "Scanning…" state, the proposal table
 * with rule spans locked and AI spans deselectable through their checkbox, the
 * rules-only and fallback notes, the empty result, the error card, the
 * Reject / Approve buttons and the dialog's own close.
 *
 * @spec openspec/specs/woo-llm-anonymisation/spec.md#requirement-redaction-proposals-require-explicit-human-review-before-any-hand-off
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'canClose'],
		emits: ['closing'],
		template: '<div><button data-testid="x" @click="$emit(\'closing\')" /><slot /><div class="actions"><slot name="actions" /></div></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'type'],
		emits: ['click'],
		template: '<button :class="type" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div :class="\'note-\' + type"><slot /></div>' },
}))

const { default: RedactionAssistDialog } = await import('../../src/dialogs/RedactionAssistDialog.vue')

const BASE = '/index.php/apps/dossiq/api/cases/c1/woo/documents/d1/redaction-proposal'
const TEXT = 'BSN 123456782 belongs to Jan Jansen.'

/**
 * Mount the dialog.
 *
 * @param {object} props Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(props = {}) {
	const w = mount(RedactionAssistDialog, { props: { caseId: 'c1', documentRef: 'd1', ...props } })
	await flushPromises()
	return w
}

/**
 * The action button with this label.
 *
 * @param {object} w The wrapper.
 * @param {string} label The label.
 * @return {object} The button.
 */
function button(w, label) {
	return w.findAll('.actions button').find((b) => b.text() === label)
}

/**
 * Type the text and detect, answered with `data`.
 *
 * @param {object} w The wrapper.
 * @param {object} data The proposal.
 * @return {Promise<void>}
 */
async function detectWith(w, data) {
	await w.find('textarea').setValue(TEXT)
	axios.post.mockResolvedValueOnce({ data })
	await button(w, 'Detect redaction candidates').trigger('click')
	await flushPromises()
}

beforeEach(() => {
	axios.post.mockReset()
})

describe('RedactionAssistDialog template', () => {
	it('detects what was typed, showing Scanning… while it waits', async () => {
		const w = await mountIt()
		expect(button(w, 'Detect redaction candidates').attributes('disabled')).toBeDefined()

		await w.find('textarea').setValue(TEXT)
		expect(w.text()).toContain(`${TEXT.length} / 12000 characters`)
		let answer
		axios.post.mockReturnValueOnce(new Promise((resolve) => { answer = resolve }))
		await button(w, 'Detect redaction candidates').trigger('click')

		expect(axios.post).toHaveBeenCalledWith(BASE, { text: TEXT })
		expect(button(w, 'Scanning…').attributes('disabled')).toBeDefined()
		expect(w.find('textarea').attributes('disabled')).toBeDefined()

		answer({ data: { source: 'llm', spans: [] } })
		await flushPromises()
		expect(w.find('textarea').exists()).toBe(false)
		expect(w.text()).toContain('No redaction candidates found.')
	})

	it('lists the spans, locks rule spans and approves only what stays checked', async () => {
		const w = await mountIt()
		await detectWith(w, {
			source: 'llm',
			spans: [
				{ start: 4, end: 13, category: 'bsn', source: 'rule' },
				{ start: 26, end: 36, category: 'name', source: 'llm' },
			],
		})

		const rows = w.findAll('tbody tr')
		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('Rule (always applied)')
		expect(rows[1].text()).toContain('AI-proposed')
		const boxes = w.findAll('input[type="checkbox"]')
		expect(boxes[0].attributes('disabled')).toBeDefined()
		expect(boxes[1].attributes('disabled')).toBeUndefined()
		expect(boxes[1].attributes('aria-label')).toBe('Select redaction candidate name')

		await boxes[1].setValue(false)
		expect(w.vm.selections).toEqual({ 0: true, 1: false })

		let answer
		axios.post.mockReturnValueOnce(new Promise((resolve) => { answer = resolve }))
		await button(w, 'Approve selected').trigger('click')
		expect(button(w, 'Applying…').attributes('disabled')).toBeDefined()
		expect(button(w, 'Reject').attributes('disabled')).toBeDefined()

		answer({ data: { applied: 1 } })
		await flushPromises()
		expect(axios.post).toHaveBeenLastCalledWith(`${BASE}/review`, {
			decision: 'approve',
			spans: [{ start: 4, end: 13, category: 'bsn', source: 'rule' }],
		})
		expect(w.emitted('reviewed')).toEqual([[{ applied: 1 }]])
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('says AI assist is unavailable and rejects from the Reject button', async () => {
		const w = await mountIt({ initialText: TEXT })
		axios.post.mockResolvedValueOnce({ data: { source: 'rules_only', spans: [{ start: 4, end: 13, category: 'bsn', source: 'rule' }] } })
		await button(w, 'Detect redaction candidates').trigger('click')
		await flushPromises()
		expect(w.find('.note-info').text()).toBe('AI assist is currently unavailable. Showing rule-based matches only.')

		axios.post.mockResolvedValueOnce({ data: { rejected: true } })
		await button(w, 'Reject').trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenLastCalledWith(`${BASE}/review`, {
			decision: 'reject',
			spans: [{ start: 4, end: 13, category: 'bsn', source: 'rule' }],
		})
		expect(w.emitted('reviewed')).toEqual([[{ rejected: true }]])
	})

	it('names the AI failure when it fell back to the rules', async () => {
		const w = await mountIt()
		await detectWith(w, { source: 'rules_only_fallback', llmError: 'timeout', spans: [] })
		expect(w.find('.note-warning').text()).toBe('AI-assisted detection failed (timeout). Falling back to rule-based matches only.')

		const blank = await mountIt()
		await detectWith(blank, { source: 'rules_only_fallback', spans: [] })
		expect(blank.find('.note-warning').text()).toBe('AI-assisted detection failed (). Falling back to rule-based matches only.')
	})

	it('shows a failed detection in an error card and keeps the text', async () => {
		const w = await mountIt()
		await w.find('textarea').setValue(TEXT)
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Model offline' } } })
		await button(w, 'Detect redaction candidates').trigger('click')
		await flushPromises()

		expect(w.find('.note-error').text()).toBe('Model offline')
		expect(w.find('textarea').element.value).toBe(TEXT)
		expect(button(w, 'Detect redaction candidates').attributes('disabled')).toBeUndefined()
	})

	it('closes from the dialog itself', async () => {
		const w = await mountIt()
		await w.find('[data-testid="x"]').trigger('click')
		expect(w.emitted('close')).toHaveLength(1)
		expect(axios.post).not.toHaveBeenCalled()
	})
})
