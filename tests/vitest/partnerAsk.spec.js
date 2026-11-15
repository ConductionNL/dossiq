/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ask a handler sends to a partner through portaliq: what is refused
 * before sending, the body portaliq's ask route receives, and the sentence
 * for each refusal it answers with.
 *
 * @spec openspec/specs/partner-tasks-in-the-portal/spec.md (portaliq, REQ-PTP-002)
 */

import { describe, expect, it, vi } from 'vitest'

const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { post: (...a) => mockPost(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => '/index.php' + u }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, s) => s }))

const { askFailure, askPayload, askRefusal, emptyAsk, sendAsk } = await import('../../src/services/partnerAsk.js')

function filled () {
  return {
	...emptyAsk(),
	title: ' Welstandsadvies ',
	description: ' Graag advies over de gevel. ',
	dueAt: '2026-11-29',
	kvk: ' 12345678 ',
	email: ' welstand@example.nl ',
	name: ' Welstandscommissie ',
	pdfRequired: true,
}
}

describe('askRefusal', () => {
	it('accepts a complete ask', () => {
		expect(askRefusal(filled(), 'case-1')).toBe('')
	})

	it('refuses without a case, title, KvK number or e-mail address', () => {
		expect(askRefusal(filled(), '')).toBe('Open a case first.')
		expect(askRefusal({ ...filled(), title: '  ' }, 'case-1')).toBe('Say what the partner is asked for.')
		expect(askRefusal({ ...filled(), kvk: '1234567' }, 'case-1')).toBe('Enter the partner\'s KvK number of eight digits.')
		expect(askRefusal({ ...filled(), kvk: '1234567a' }, 'case-1')).toBe('Enter the partner\'s KvK number of eight digits.')
		expect(askRefusal({ ...filled(), email: 'welstand' }, 'case-1')).toBe('Enter the partner\'s e-mail address.')
	})

	it('accepts no due date and refuses one that is not a date', () => {
		expect(askRefusal({ ...filled(), dueAt: '' }, 'case-1')).toBe('')
		expect(askRefusal({ ...filled(), dueAt: '29-11-2026' }, 'case-1')).toBe('Choose the date the answer is wanted by.')
	})
})

describe('askPayload', () => {
	it('names the case and the partner, trimmed, with a required PDF', () => {
		expect(askPayload(filled(), 'case-1')).toEqual({
			register: 'dossiq',
			schema: 'case',
			caseId: 'case-1',
			title: 'Welstandsadvies',
			description: 'Graag advies over de gevel.',
			dueAt: '2026-11-29',
			partner: { kvk: '12345678', email: 'welstand@example.nl', name: 'Welstandscommissie' },
			uploadRules: [{ mimeType: 'application/pdf', required: true }],
		})
	})

	it('leaves out an empty name and asks for no upload when no PDF is required', () => {
		const body = askPayload({ ...filled(), name: ' ', pdfRequired: false }, 'case-1')

		expect(body.partner).toEqual({ kvk: '12345678', email: 'welstand@example.nl' })
		expect(body.uploadRules).toEqual([])
	})
})

describe('sendAsk', () => {

	it('posts the body to portaliq and answers its task', async () => {
		mockPost.mockResolvedValue({ data: { task: { id: 't-1' } } })
		const body = askPayload(filled(), 'case-1')

		expect(await sendAsk(body)).toEqual({ ok: true, data: { task: { id: 't-1' } }, error: '' })
		expect(mockPost).toHaveBeenCalledWith('/index.php/apps/portaliq/api/partner-tasks/ask', body)
	})

	it('answers the sentence for a refusal', async () => {
		const refused = new Error('403'); refused.response = { status: 403 }; mockPost.mockRejectedValue(refused)

		expect(await sendAsk({})).toEqual({ ok: false, data: null, error: 'You may not ask a partner on this case.' })
	})
})

describe('askFailure', () => {
	it('names each refusal and falls back for anything else', () => {
		expect(askFailure(400)).toBe('The ask was not raised. Check the partner\'s KvK number and e-mail address.')
		expect(askFailure(404)).toBe('Portaliq is not installed here, so a partner cannot be asked.')
		expect(askFailure(500)).toBe('The ask could not be sent. Try again in a moment.')
		expect(askFailure(undefined)).toBe('The ask could not be sent. Try again in a moment.')
	})
})
