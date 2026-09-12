// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A broken map must not look like an empty one.
 *
 * `fetch()` does not reject on an HTTP error: a 500 with a JSON body resolves
 * like any other response. CasesOnMapView read the body without ever looking
 * at the status, found no `results` key, and rendered an empty map with no
 * notice. A server error therefore reached the user as "there are no cases
 * here", which is the one reading that stops them asking.
 *
 * The notice already existed for a request that fails outright. These tests
 * pin it to the status instead of to the shape of the body.
 *
 * @spec openspec/specs/case-map-overview/spec.md
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnMapWidget: {} }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: {} }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: {} }))
vi.mock('vue-material-design-icons/Alert.vue', () => ({ default: {} }))
vi.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({ default: {} }))
vi.mock('../../src/services/casesOnMapApi.js', () => ({
	registerCasesOnMapOverview: () => {},
}))

const { default: CasesOnMapView } =
	await import('../../src/views/CasesOnMapView.vue')

/**
 * Run the view's reload() against one canned response.
 *
 * @param {object} response What the case-rows request answers.
 * @return {Promise<object>} The view state reload() left behind.
 */
async function reloadWith(response) {
	const context = {
		register: 'dossiq',
		schema: 'case',
		filterCaseType: null,
		filterStatus: null,
		loading: false,
		degraded: false,
		points: [],
		total: 0,
		firstLatLng: CasesOnMapView.methods.firstLatLng,
	}
	vi.stubGlobal('fetch', async () => response)

	await CasesOnMapView.methods.reload.call(context)

	return context
}

describe('Cases on map, a failed load', () => {
	beforeEach(() => {
		vi.unstubAllGlobals()
	})

	it('shows the notice when the server answers 500 with a JSON body', async () => {
		const state = await reloadWith({
			ok: false,
			status: 500,
			json: async () => ({ error: 'Internal Server Error' }),
		})

		expect(state.degraded).toBe(true)
		expect(state.points).toEqual([])
	})

	it('shows the notice on any other unsuccessful status, body or not', async () => {
		const state = await reloadWith({
			ok: false,
			status: 403,
			json: async () => ({
				results: [
					{
						id: 'c-1',
						title: 'Leaked',
						geometry: '{"type":"Point","coordinates":[5,52]}',
					},
				],
			}),
		})

		expect(state.degraded).toBe(true)
		expect(state.points).toEqual([])
	})

	it('keeps quiet when the load succeeds and there is simply nothing to show', async () => {
		const state = await reloadWith({
			ok: true,
			status: 200,
			json: async () => ({ results: [] }),
		})

		expect(state.degraded).toBe(false)
		expect(state.points).toEqual([])
	})

	it('still plots the cases a successful load returns', async () => {
		const state = await reloadWith({
			ok: true,
			status: 200,
			json: async () => ({
				results: [
					{
						id: 'c-1',
						title: 'Dakkapel',
						geometry: '{"type":"Point","coordinates":[5.1,52.1]}',
					},
				],
			}),
		})

		expect(state.degraded).toBe(false)
		expect(state.points).toHaveLength(1)
		expect(state.points[0].title).toBe('Dakkapel')
	})
})
