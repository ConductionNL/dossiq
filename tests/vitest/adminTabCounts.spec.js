/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin page's Integrations and Map layers tabs carry how many there are
 * (board DqInstellingenAlgemeen), read from OpenRegister's list totals; a list
 * that cannot be read leaves its tab without a number.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

const { fetchAdminTabCounts } = await import('../../src/services/adminTabCounts.js')
const { houseStyleFields } = await import('../../src/views/settings/adminFields.js')

describe('the admin tab counts', () => {
	beforeEach(() => {
		get.mockReset()
	})

	it('counts dossiq\'s connections and the map layers from the list totals', async () => {
		get.mockImplementation(async (url) => ({ data: { total: url.includes('app_connection') ? 9 : 4 } }))

		expect(await fetchAdminTabCounts()).toEqual({ integrations: 9, 'map-layers': 4 })
		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/integriq/app_connection',
			{ params: { app: 'dossiq', _limit: 1 } },
		)
		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/dossiq/wmsLayer',
			{ params: { _limit: 1 } },
		)
	})

	it('leaves a tab without a number when its list cannot be read', async () => {
		get.mockImplementation(async (url) => {
			if (url.includes('wmsLayer')) {
				throw new Error('forbidden')
			}
			return { data: {} }
		})

		expect(await fetchAdminTabCounts()).toEqual({})
	})
})

describe('the house style logo field', () => {
	it('reads Other logo on its upload button, as the board draws it', () => {
		const logo = houseStyleFields((app, text) => text).find((field) => field.key === 'logo')

		expect(logo.uploadLabel).toBe('Other logo')
	})
})
