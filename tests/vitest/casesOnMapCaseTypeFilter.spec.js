// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A filter with no options cannot narrow anything.
 *
 * `CasesOnMapView` declared `caseTypeOptions: []` and nothing ever wrote to
 * it, so the Case type control rendered empty on every visit. It looked like
 * a working filter, which is why it survived: the page had the control, the
 * scenario's clause named the control, and nobody asked the control what it
 * was offering.
 *
 * These tests ask. One pins that mounting the view fills the options from the
 * `caseType` collection the Cases index narrows by; one pins that they are
 * read the way that page reads them (`@self.id` + `title`); and one pins that
 * the chosen option reaches the wire as its id, because an option object put
 * straight into a query string becomes `[object Object]` and matches no case.
 *
 * @spec openspec/specs/case-map-overview/spec.md
 */
import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnMapWidget: {} }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: {} }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: {} }))
vi.mock('vue-material-design-icons/Alert.vue', () => ({ default: {} }))
vi.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({ default: {} }))

// The overview declaration is fire-and-forget plumbing; everything else in
// this module is the code under test, so only that one export is replaced.
vi.mock('../../src/services/casesOnMapApi.js', async (importOriginal) => ({
	...(await importOriginal()),
	registerCasesOnMapOverview: vi.fn(),
}))

const { default: CasesOnMapView } =
	await import('../../src/views/CasesOnMapView.vue')
const { fetchCaseTypeOptions } =
	await import('../../src/services/casesOnMapApi.js')

const CASE_TYPE_ROWS = [
	{ '@self': { id: 'ct-handhaving' }, title: 'Handhaving' },
	{ '@self': { id: 'ct-vergunning' }, title: 'Vergunning' },
]

/**
 * A component instance shaped the way Vue would shape it: the declared data
 * plus the declared methods, bound to one object.
 *
 * @return {object} The stand-in instance.
 */
function instance() {
	const vm = { register: 'dossiq', schema: 'case', ...CasesOnMapView.data() }
	for (const [name, fn] of Object.entries(CasesOnMapView.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

describe('Cases on map, the case type filter', () => {
	beforeEach(() => {
		vi.unstubAllGlobals()
		axios.get.mockReset()
	})

	it('reads the caseType collection of the register, keyed on @self.id and labelled with title', async () => {
		axios.get.mockResolvedValue({ data: { results: CASE_TYPE_ROWS } })

		const options = await fetchCaseTypeOptions({ register: 'dossiq' })

		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/dossiq/caseType',
			{ params: { _limit: 200 } },
		)
		expect(options).toEqual([
			{ id: 'ct-handhaving', label: 'Handhaving' },
			{ id: 'ct-vergunning', label: 'Vergunning' },
		])
	})

	it('fills the filter when the view mounts', async () => {
		axios.get.mockResolvedValue({ data: { results: CASE_TYPE_ROWS } })
		vi.stubGlobal('fetch', async () => ({
			ok: true,
			status: 200,
			json: async () => ({ results: [] }),
		}))

		const vm = instance()
		expect(vm.caseTypeOptions).toEqual([])

		CasesOnMapView.mounted.call(vm)
		// mounted() fires the two loads and does not await them, exactly as Vue
		// calls it; drain the microtask queue the way a rendered frame would.
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(vm.caseTypeOptions).toEqual([
			{ id: 'ct-handhaving', label: 'Handhaving' },
			{ id: 'ct-vergunning', label: 'Vergunning' },
		])
	})

	it('sends the chosen case type as its id, not as the option object', async () => {
		const requested = []
		vi.stubGlobal('fetch', async (url) => {
			requested.push(url)
			return { ok: true, status: 200, json: async () => ({ results: [] }) }
		})

		const vm = instance()
		vm.filterCaseType = { id: 'ct-handhaving', label: 'Handhaving' }

		await vm.reload()

		expect(requested).toHaveLength(1)
		expect(requested[0]).toContain('caseType=ct-handhaving')
		expect(requested[0]).not.toContain('object+Object')
	})
})
