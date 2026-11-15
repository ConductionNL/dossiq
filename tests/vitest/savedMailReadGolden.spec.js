/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `readFileAsMessage` (src/utils/savedMail.js):
 * which argument is the clicked node, which file ids it refuses, the request
 * it makes, and every sentence it shows.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { post: (...a) => mockPost(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
const mockSuccess = vi.fn()
const mockError = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (...a) => mockSuccess(...a),
	showError: (...a) => mockError(...a),
}))
const mockEmit = vi.fn()
vi.mock('@nextcloud/event-bus', () => ({ emit: (...a) => mockEmit(...a) }))

const { readFileAsMessage } = await import('../../src/utils/savedMail.js')

const MAIL = { fileid: 77, basename: 'bezwaar.eml' }

beforeEach(() => {
	mockPost.mockReset()
	mockSuccess.mockReset()
	mockError.mockReset()
	mockEmit.mockReset()
	vi.stubGlobal('window', { location: { pathname: '/apps/dossiq/cases/case%201' } })
})

describe('readFileAsMessage golden', () => {
	it('takes the last object argument as the node, unwrapping an item', async () => {
		mockPost.mockResolvedValue({ data: { outcome: 'imported', subject: 's' } })
		await readFileAsMessage('action', { fileid: 1, basename: 'a.jpg' }, null, 3, { item: { fileId: 8, name: 'b.MSG' } })
		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/cases/case%201/files/8/read-as-message')
	})

	it('keeps an argument whose item is not an object as the node itself', async () => {
		mockPost.mockResolvedValue({ data: { outcome: 'imported' } })
		await readFileAsMessage({ item: 'x', fileid: 9, fileName: 'c.eml' })
		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/cases/case%201/files/9/read-as-message')
		expect(mockSuccess).toHaveBeenCalledWith('Filed as a message: ')
	})

	it.each([
		[[]],
		[[null, 'x']],
		[[{ fileid: 0, basename: 'a.eml' }]],
		[[{ fileid: -3, basename: 'a.eml' }]],
		[[{ fileid: 'abc', basename: 'a.eml' }]],
		[[{ fileid: Infinity, basename: 'a.eml' }]],
	])('does nothing for %j', async (args) => {
		await readFileAsMessage(...args)
		expect(mockPost).not.toHaveBeenCalled()
		expect(mockError).not.toHaveBeenCalled()
	})

	it('says a non-mail file holds no message', async () => {
		await readFileAsMessage({ fileid: 77, basename: 'foto.jpg' })
		expect(mockError).toHaveBeenCalledWith('This is not a saved mail file, so there is no message in it to read.')
	})

	it('says a file off a case is not on a case', async () => {
		vi.stubGlobal('window', { location: { pathname: '/apps/files' } })
		await readFileAsMessage(MAIL)
		expect(mockError).toHaveBeenCalledWith('This file is not on a case.')
	})

	it('files the message, names its subject and refreshes the page', async () => {
		mockPost.mockResolvedValue({ data: { outcome: 'imported', subject: 'Bezwaar' } })
		await readFileAsMessage(MAIL)
		expect(mockSuccess).toHaveBeenCalledWith('Filed as a message: Bezwaar')
		expect(mockEmit).toHaveBeenCalledWith('cn:page:refresh')
		expect(mockError).not.toHaveBeenCalled()
	})

	it.each([
		[{ data: { outcome: 'kept', reason: 'No reader.' } }, 'No reader.'],
		[{ data: { outcome: 'kept' } }, 'This file could not be read as a message.'],
		[{ data: null }, 'This file could not be read as a message.'],
		[{}, 'This file could not be read as a message.'],
	])('shows the reason for an answer that is not a message (%j)', async (answer, sentence) => {
		mockPost.mockResolvedValue(answer)
		await readFileAsMessage(MAIL)
		expect(mockError).toHaveBeenCalledWith(sentence)
		expect(mockSuccess).not.toHaveBeenCalled()
		expect(mockEmit).not.toHaveBeenCalled()
	})

	it.each([
		[{ response: { data: { error: 'Refused.' } } }, 'Refused.'],
		[{ response: {} }, 'This file could not be read as a message.'],
		[new Error('net'), 'This file could not be read as a message.'],
	])('shows what a failed request answered (%j)', async (failure, sentence) => {
		mockPost.mockRejectedValue(failure)
		await readFileAsMessage(MAIL)
		expect(mockError).toHaveBeenCalledWith(sentence)
		expect(mockSuccess).not.toHaveBeenCalled()
	})
})
