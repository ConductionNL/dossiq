// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What dossiq takes from @conduction/nextcloud-vue 2.78.0, read from the
 * build the bundle ships (`dist/esm`), not from the library's `src`.
 *
 * On 2.77.0 closing a case page sent its presence beacon with no body. A
 * beacon cannot set headers, so the CSRF token never reached OpenRegister,
 * and on cloud.conduction.nl every dossiq case page logged a 405 (412 once
 * OpenRegister registered POST on `/presence`). 2.78.0 puts the token in a
 * FormData body.
 *
 * On 2.77.0 a case form that failed to save could still say "Saved" next to
 * the server error. 2.78.0 says "Draft kept on this device" there, or
 * nothing.
 *
 * @spec openspec/changes/nextcloud-vue-2-78-presence-and-draft/specs/case-management/spec.md
 */

import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const put = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: {
		put: (...args) => put(...args),
		delete: vi.fn().mockResolvedValue({ data: {} }),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url) => `/index.php${url}`,
}))
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'admin' }),
	getRequestToken: () => 'token-from-the-page',
}))

const LIBRARY = path.resolve(
	__dirname,
	'../../node_modules/@conduction/nextcloud-vue/dist/esm',
)

describe('the presence beacon when a case page closes (nextcloud-vue ^2.78.0)', () => {
	let sendBeacon

	beforeEach(() => {
		put.mockReset()
		put.mockResolvedValue({ data: { present: [], beatSeconds: 30 } })
		sendBeacon = vi.fn().mockReturnValue(true)
		Object.defineProperty(window.navigator, 'sendBeacon', {
			configurable: true,
			value: sendBeacon,
		})
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('posts to the departure route with the CSRF token in a form body', async () => {
		const { effectScope } = await import('vue')
		const { useObjectPresence } = await import(
			path.join(LIBRARY, 'composables', 'useObjectPresence.js')
		)
		const scope = effectScope()
		scope.run(() =>
			useObjectPresence(
				() => 'dossiq',
				() => 'case',
				() => 'b3a1c2d4-0000-4000-8000-000000000001',
			),
		)
		// The first beat makes the reader active; only an active reader departs.
		await vi.waitFor(() => expect(put).toHaveBeenCalledTimes(1))
		await new Promise((resolve) => setTimeout(resolve, 0))

		window.dispatchEvent(new Event('beforeunload'))
		scope.stop()

		expect(sendBeacon).toHaveBeenCalledTimes(1)
		const [url, body] = sendBeacon.mock.calls[0]
		expect(url).toBe(
			'/index.php/apps/openregister/api/objects/dossiq/case/b3a1c2d4-0000-4000-8000-000000000001/presence?_method=DELETE',
		)
		expect(body).toBeInstanceOf(FormData)
		expect(body.get('requesttoken')).toBe('token-from-the-page')
	})
})

describe('the draft indicator beside a failed save (nextcloud-vue ^2.78.0)', () => {
	it('says the draft is kept on this device, never "Saved"', async () => {
		const { draftIndicatorText } = await import(
			path.join(LIBRARY, 'composables', 'useFormDraft.js')
		)
		const now = Date.now()
		const text = draftIndicatorText('saved', now - 5000, now, {
			saveFailed: true,
		})

		expect(text).toBe('Draft kept on this device')
		expect(text).not.toMatch(/saved/i)
	})

	it('says nothing while a draft is being written after a failed save', async () => {
		const { draftIndicatorText } = await import(
			path.join(LIBRARY, 'composables', 'useFormDraft.js')
		)
		const now = Date.now()

		expect(draftIndicatorText('saving', now, now, { saveFailed: true })).toBe('')
	})

	it('still says "Saved" when nothing failed', async () => {
		const { draftIndicatorText } = await import(
			path.join(LIBRARY, 'composables', 'useFormDraft.js')
		)
		const now = Date.now()

		expect(draftIndicatorText('saved', now - 5000, now)).toMatch(/saved/i)
	})
})
