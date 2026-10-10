// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * 🔴 AN EMPTY LEDGER IS NOT AN EMPTY REGISTER. The shipped ledger records only
 * what a starter set seeded. The 24 case types on the cloud came with the
 * register import, and "What shipped with dossiq" said "Nothing has been
 * seeded yet" beside them (round-4 cloud check). The real component is
 * mounted against an empty ledger and each answer the count can give.
 *
 * @spec openspec/changes/r5-admin-settings-and-tour-tell-the-truth/specs/admin-settings/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

/** What the objects endpoint answers for the count. Replaced per test. */
let total = 0

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (url) => {
			if (url.includes('/api/starter/shipped/')) {
				return Promise.resolve({ data: { items: [], total: 0 } })
			}
			if (total instanceof Error) {
				return Promise.reject(total)
			}
			return Promise.resolve({ data: { results: [], total } })
		},
		post: () => Promise.resolve({ data: {} }),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => '/index.php' + path,
}))
vi.mock('@nextcloud/dialogs', () => ({
	showError: () => {},
	showSuccess: () => {},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', render: () => null },
}))

const { default: ShippedConfiguration } =
	await import('../../src/views/settings/ShippedConfiguration.vue')

/**
 * The text of the empty row once the screen has read both answers.
 *
 * @param {number|Error} answer What the count endpoint answers.
 * @return {Promise<string>} The row's text.
 */
async function emptyRow(answer) {
	total = answer
	const wrapper = mount(ShippedConfiguration, {
		global: { mocks: { t: (_app, text) => text } },
	})
	await flushPromises()

	return wrapper.find('[data-testid="shipped-configuration-empty"]').text()
}

describe('the empty shipped table', () => {
	it('says how many there are when the register holds objects the ledger does not', async () => {
		const text = await emptyRow(24)

		expect(text).toContain('24')
		expect(text).toContain('None came from a starter set')
		expect(text).not.toContain('Nothing has been seeded')
	})

	it('says nothing has been seeded only when the register holds none', async () => {
		expect(await emptyRow(0)).toBe('Nothing has been seeded yet.')
	})

	it('keeps the old sentence when the count cannot be read', async () => {
		expect(await emptyRow(new Error('503'))).toBe('Nothing has been seeded yet.')
	})
})
