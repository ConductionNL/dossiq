// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for PortalMessageDialog's `send`
 * (src/dialogs/PortalMessageDialog.vue): what it refuses before writing, the
 * message it writes, the events it emits in order, and every sentence it
 * shows.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const mockUser = vi.fn()
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => mockUser() }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
const mockBus = vi.fn()
vi.mock('@nextcloud/event-bus', () => ({ emit: (...a) => mockBus(...a) }))
const mockLog = vi.fn()
vi.mock('../../src/services/pipelinqCaseApi.js', () => ({ logContactMoment: (...a) => mockLog(...a) }))

const { default: Dialog } = await import('../../src/dialogs/PortalMessageDialog.vue')

const NOW = '2026-11-10T12:00:00.000Z'

/**
 * A stand-in for the dialog instance, carrying every method.
 *
 * @param {object} state Fields to set.
 * @return {object} The instance.
 */
function instance(state = {}) {
	const events = []
	return {
		...Dialog.methods,
		resolvedCaseId: 'case-1',
		kase: { portalSubject: 'sub-1', identifier: 'Z-1' },
		form: { subject: ' Hi ', content: ' Body ' },
		logContactMoment: false,
		error: 'old',
		sending: null,
		events,
		$emit: (...a) => events.push(['emit', ...a]),
		...state,
	}
}

describe('portal message send golden', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date(NOW))
		axios.post.mockReset()
		mockUser.mockReset()
		mockBus.mockReset()
		mockLog.mockReset()
		mockUser.mockReturnValue({ uid: 'anna', displayName: 'Anna' })
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('refuses an empty message', async () => {
		const vm = instance({ form: { subject: 'S', content: '   ' } })
		await vm.send()
		expect(vm.error).toBe('Write a message first.')
		expect(axios.post).not.toHaveBeenCalled()
		expect(vm.sending).toBeNull()
	})

	it.each([[null], [{}], [{ portalSubject: '' }]])('refuses a case without a portal account (%j)', async (kase) => {
		const vm = instance({ kase })
		await vm.send()
		expect(vm.error).toBe('This case has no portal account to write to. Nothing was sent.')
		expect(axios.post).not.toHaveBeenCalled()
		expect(mockUser).not.toHaveBeenCalled()
	})

	it('writes the message, then emits sent, the reply-sent bus event and close', async () => {
		axios.post.mockImplementation(async () => {
			mockBus('posted')
			return { data: { id: 'm1' } }
		})
		const vm = instance()
		mockBus.mockImplementation((...a) => vm.events.push(['bus', ...a]))
		await vm.send()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/dossiq/portaalBericht', {
			caseId: 'case-1',
			caseReference: 'Z-1',
			recipientRef: 'sub-1',
			senderRef: 'anna',
			senderType: 'medewerker',
			senderName: 'Anna',
			subject: 'Hi',
			content: 'Body',
			direction: 'handler_to_citizen',
			sentAt: NOW,
		})
		expect(vm.events).toEqual([
			['bus', 'posted'],
			['emit', 'sent', { id: 'm1' }],
			['bus', 'cn:conversation:reply-sent', { objectId: 'case-1' }],
			['emit', 'close'],
		])
		expect(vm.error).toBe('')
		expect(vm.sending).toBe(false)
		expect(mockLog).not.toHaveBeenCalled()
	})

	it.each([
		[{ uid: 'anna' }, 'anna', 'anna'],
		[null, '', ''],
		[{ displayName: 'Only name' }, '', 'Only name'],
	])('names the sender from the current user (%j)', async (user, ref, name) => {
		mockUser.mockReturnValue(user)
		axios.post.mockResolvedValue({ data: {} })
		await instance({ kase: { portalSubject: 'sub-1' } }).send()
		const payload = axios.post.mock.calls[0][1]
		expect(payload.senderRef).toBe(ref)
		expect(payload.senderName).toBe(name)
		expect(payload.caseReference).toBe('')
	})

	it('logs the message as a contact moment and closes when asked to', async () => {
		axios.post.mockResolvedValue({ data: {} })
		mockLog.mockResolvedValue({})
		const vm = instance({ logContactMoment: true })
		await vm.send()
		expect(mockLog).toHaveBeenCalledWith('case-1', {
			notificationChannel: 'webformulier',
			direction: 'outbound',
			startTime: NOW,
			summary: 'Body',
			visibleToApplicant: true,
		})
		expect(vm.events.at(-1)).toEqual(['emit', 'close'])
		expect(vm.error).toBe('')
	})

	it('stays open and says so when the contact moment cannot be logged', async () => {
		axios.post.mockResolvedValue({ data: {} })
		mockLog.mockRejectedValue(new Error('no'))
		const vm = instance({ logContactMoment: true })
		await vm.send()
		expect(vm.error).toBe('The message was sent, but it could not be logged as a contact moment.')
		expect(vm.events).toEqual([['emit', 'sent', {}]])
		expect(vm.sending).toBe(false)
	})

	it('says the message could not be sent when the write fails', async () => {
		axios.post.mockRejectedValue(new Error('net'))
		const vm = instance()
		await vm.send()
		expect(vm.error).toBe('The message could not be sent. Try again in a moment.')
		expect(vm.events).toEqual([])
		expect(mockBus).not.toHaveBeenCalled()
		expect(vm.sending).toBe(false)
	})
})
