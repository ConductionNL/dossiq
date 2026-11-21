// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PublicFederatedTransferPage's respond: the scoped-token call it makes, and
 * the outcome it shows for an accepted, a rejected, a refused and an invalid
 * transfer.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md#a-remote-org-accepts-a-transfer-addressed-to-it-via-its-scoped-token
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockPut = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { put: (...a) => mockPut(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (app, text) => text,
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', template: '<button><slot /></button>' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note"><slot /></div>' },
}))

const { default: PublicFederatedTransferPage } =
	await import('../../src/views/public/PublicFederatedTransferPage.vue')
const { publicFederatedTransferEndpoint } = await import('../../src/utils/federatedShareHelpers.js')

/**
 * @return {object} The mounted page.
 */
function page() {
	return mount(PublicFederatedTransferPage, { props: { shareToken: 'tok-1', transferId: 'tr-1' } })
}

beforeEach(() => {
	mockPut.mockReset()
})

describe('PublicFederatedTransferPage respond', () => {
	it('sends the action and the reason to the scoped-token endpoint', async () => {
		mockPut.mockResolvedValue({ data: { success: true } })
		const wrapper = page()
		wrapper.vm.reason = 'Niet onze zaak'
		await wrapper.vm.respond('reject')

		expect(mockPut).toHaveBeenCalledWith(
			`/index.php${publicFederatedTransferEndpoint('tok-1', 'tr-1')}`,
			{ action: 'reject', reason: 'Niet onze zaak' },
		)
		expect(wrapper.vm.submitting).toBe(false)
	})

	it.each([
		['accept', 'You have accepted this case transfer.'],
		['reject', 'You have rejected this case transfer.'],
		['other', 'You have rejected this case transfer.'],
	])('shows an allowed %s as done', async (action, message) => {
		mockPut.mockResolvedValue({ data: { success: true } })
		const wrapper = page()
		await wrapper.vm.respond(action)
		expect(wrapper.vm.result).toEqual({ success: true, message })
	})

	it.each([
		[{ success: false, error: 'Transfer is not addressed to you' }, 'Transfer is not addressed to you'],
		[{ success: false }, 'Could not process this transfer.'],
		[null, 'Could not process this transfer.'],
		[{ success: 1 }, 'You have accepted this case transfer.'],
	])('shows a refused answer %j as refused', async (data, message) => {
		mockPut.mockResolvedValue({ data })
		const wrapper = page()
		await wrapper.vm.respond('accept')
		expect(wrapper.vm.result).toEqual({ success: data?.success === 1, message })
	})

	it.each([
		[{ response: { data: { error: 'Token expired' } } }, 'Token expired'],
		[{ response: { status: 404 } }, 'This transfer link is invalid, expired or already resolved.'],
		[new Error('network'), 'This transfer link is invalid, expired or already resolved.'],
	])('shows a failed call as an invalid link (%j)', async (error, message) => {
		mockPut.mockRejectedValue(error)
		const wrapper = page()
		await wrapper.vm.respond('accept')
		expect(wrapper.vm.result).toEqual({ success: false, message })
		expect(wrapper.vm.submitting).toBe(false)
	})

	it('reads a missing response as an invalid link and renders the outcome', async () => {
		mockPut.mockResolvedValue(undefined)
		const wrapper = page()
		await wrapper.vm.respond('accept')
		await flushPromises()
		expect(wrapper.vm.result.success).toBe(false)
		expect(wrapper.find('.note').text()).toBe('This transfer link is invalid, expired or already resolved.')
	})
})
