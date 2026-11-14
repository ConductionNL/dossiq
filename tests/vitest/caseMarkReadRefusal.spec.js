// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Marking a case read from its row when the write is refused.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { markCaseRead } from '../../src/utils/caseUnread.js'

const mockShowSuccess = vi.fn()
const mockShowError = vi.fn()
const mockMarkRead = vi.fn()
const mockAnnounce = vi.fn()

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (...a) => mockShowSuccess(...a),
	showError: (...a) => mockShowError(...a),
}))

vi.mock('../../src/services/readStateApi.js', () => ({
	markRead: (...a) => mockMarkRead(...a),
	markUnread: vi.fn(),
}))

vi.mock('../../src/utils/casesChanged.js', () => ({
	announceCasesChanged: (...a) => mockAnnounce(...a),
}))

describe('marking a case read', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('confirms and announces the change when the write lands', async () => {
		mockMarkRead.mockResolvedValue({ unread: false })

		await markCaseRead({ actionId: 'mark-read', item: { id: 'case-7' } })

		expect(mockMarkRead).toHaveBeenCalledWith('case-7')
		expect(mockShowSuccess).toHaveBeenCalledWith('You have marked this case read.')
		expect(mockAnnounce).toHaveBeenCalledWith('case-7')
		expect(mockShowError).not.toHaveBeenCalled()
	})

	it('shows the refusal the server wrote, and announces nothing', async () => {
		mockMarkRead.mockRejectedValue({
			response: { status: 403, data: { message: 'A read state is your own.' } },
		})

		await markCaseRead({ actionId: 'mark-read', item: { id: 'case-7' } })

		expect(mockShowError).toHaveBeenCalledWith('A read state is your own.')
		expect(mockShowSuccess).not.toHaveBeenCalled()
		expect(mockAnnounce).not.toHaveBeenCalled()
	})

	it('falls back to a generic message when the server wrote none', async () => {
		mockMarkRead.mockRejectedValue(new Error('Network Error'))

		await markCaseRead({ actionId: 'mark-read', item: { id: 'case-7' } })

		expect(mockShowError).toHaveBeenCalledWith('This did not work. Try again.')
	})
})
