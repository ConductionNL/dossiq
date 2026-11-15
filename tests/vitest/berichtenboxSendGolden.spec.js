// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for BerichtenboxComposeDialog's `send`
 * (src/dialogs/BerichtenboxComposeDialog.vue): the message it sends, which
 * answers count as a refusal, the sentence and code it keeps for each, and
 * when it emits `sent`.
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockSend = vi.fn()
vi.mock('../../src/services/berichtenboxApi.js', () => ({
	sendMessage: (...a) => mockSend(...a),
}))

const { default: Dialog } = await import('../../src/dialogs/BerichtenboxComposeDialog.vue')

const OPTED_OUT = 'This person asked not to receive updates about this case. Nothing was sent.'
const UNAVAILABLE = 'integriq is not available, so dossiq cannot check whether this person may be messaged. Nothing was sent.'
const NO_REASON = 'This letter was not sent, and no reason was given.'
const FAILED = 'Failed to send message'

/**
 * A stand-in for the dialog instance, carrying every method.
 *
 * @param {object} form The form.
 * @return {object} The instance.
 */
function instance(form = {}) {
	return {
		...Dialog.methods,
		form: { bsn: '123', subject: 'S', body: 'B', berichtTypeCode: null, isBesluit: false, ...form },
		errors: {},
		resolvedCaseId: 'case-1',
		sending: null,
		sendError: 'old',
		sendCode: 'old',
		$emit: vi.fn(),
	}
}

describe('berichtenbox send golden', () => {
	beforeEach(() => {
		mockSend.mockReset()
	})

	it('sends nothing when the form does not validate', async () => {
		const vm = instance({ body: '' })
		await vm.send()
		expect(mockSend).not.toHaveBeenCalled()
		expect(vm.sending).toBeNull()
		expect(vm.sendError).toBe('old')
	})

	it.each([
		[{}, '', 'case-update'],
		[{ berichtTypeCode: { code: 'K1' }, isBesluit: true }, 'K1', 'besluit'],
		[{ berichtTypeCode: { code: '' } }, '', 'case-update'],
	])('sends the message (%j)', async (form, code, category) => {
		mockSend.mockResolvedValue({ success: true })
		const vm = instance(form)
		await vm.send()
		expect(mockSend).toHaveBeenCalledWith({
			caseId: 'case-1',
			bsn: '123',
			subject: 'S',
			body: 'B',
			berichtTypeCode: code,
			category,
		})
		expect(vm.$emit).toHaveBeenCalledWith('sent')
		expect(vm.sending).toBe(false)
		expect(vm.sendError).toBeNull()
		expect(vm.sendCode).toBe('')
	})

	it.each([[{ success: true }], [null], [undefined], [{ message: { refused: 'yes' } }]])('emits sent for %j', async (answer) => {
		mockSend.mockResolvedValue(answer)
		const vm = instance()
		await vm.send()
		expect(vm.$emit).toHaveBeenCalledWith('sent')
	})

	it.each([
		[{ success: false, code: 'opted-out', error: 'x' }, 'opted-out', OPTED_OUT],
		[{ message: { refused: true, code: 'authority-unavailable' } }, 'authority-unavailable', UNAVAILABLE],
		[{ success: false, message: { code: 'opted-out' } }, 'opted-out', OPTED_OUT],
		[{ success: false, code: 'other', error: 'Provider said no.' }, 'other', 'Provider said no.'],
		[{ success: false, message: { error: 'Message said no.' } }, '', 'Message said no.'],
		[{ success: false, error: '', message: { error: 'Message said no.' } }, '', 'Message said no.'],
		[{ success: false }, '', NO_REASON],
		[{ message: { refused: true } }, '', NO_REASON],
	])('keeps the refusal of %j', async (answer, code, sentence) => {
		mockSend.mockResolvedValue(answer)
		const vm = instance()
		await vm.send()
		expect(vm.$emit).not.toHaveBeenCalled()
		expect(vm.sendCode).toBe(code)
		expect(vm.sendError).toBe(sentence)
		expect(vm.sending).toBe(false)
	})

	it.each([
		[{ response: { data: { code: 'opted-out', error: 'x' } } }, 'opted-out', OPTED_OUT],
		[{ response: { data: { code: 'authority-unavailable' } } }, 'authority-unavailable', UNAVAILABLE],
		[{ response: { data: { code: 'pki', error: 'No certificate.' } } }, 'pki', 'No certificate.'],
		[{ response: { data: {} } }, '', FAILED],
		[{}, '', FAILED],
	])('keeps what a failed send answered (%j)', async (failure, code, sentence) => {
		mockSend.mockRejectedValue(failure)
		const vm = instance()
		await vm.send()
		expect(vm.$emit).not.toHaveBeenCalled()
		expect(vm.sendCode).toBe(code)
		expect(vm.sendError).toBe(sentence)
		expect(vm.sending).toBe(false)
	})
})
