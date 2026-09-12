/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case-plan client that reads OpenRegister's case layer.
 *
 * Three things are worth pinning here, and nothing else in the suite pins
 * them. The URL, because REQ-RCMN-001 says the panel must stop talking to
 * dossiq's own cmmn-plan routes and a wrong base would keep working against
 * the engine that is about to be deleted. The shaping, because OpenRegister
 * answers a FLAT list and the panel renders a tree. And the empty-versus-
 * absent distinction, because an empty `items` list is not a plan.
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan
 */
import axios from '@nextcloud/axios'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

beforeAll(() => {
	globalThis.t = (app, text) => text
})

const importApi = async () => await import('../../src/services/casePlanApi.js')

beforeEach(() => {
	vi.clearAllMocks()
})

/** One plan as OpenRegister answers it: flat rows with numeric parent links. */
const PLAN = {
	objectUuid: 'case-1',
	settings: {},
	items: [
		{ id: 7, uuid: 'u-besluit', key: 'besluit', name: 'Besluit genomen', type: 'milestone', parentItemId: null, position: 2, state: 'available' },
		{ id: 1, uuid: 'u-intake', key: 'intake', name: 'Intake', type: 'stage', parentItemId: null, position: 0, state: 'active' },
		{ id: 3, uuid: 'u-advies', key: 'extra-advies', name: 'Extra advies', type: 'humanTask', parentItemId: 1, position: 1, state: 'enabled', discretionary: true },
		{ id: 2, uuid: 'u-controle', key: 'controle', name: 'Controle', type: 'humanTask', parentItemId: 1, position: 0, state: 'active' },
	],
	audit: [],
}

describe('fetchCasePlan', () => {
	it('reads the plan from OpenRegister, never from dossiq', async () => {
		const { fetchCasePlan } = await importApi()
		axios.get.mockResolvedValue({ data: PLAN })

		await fetchCasePlan('case-1')

		const url = axios.get.mock.calls[0][0]
		expect(url).toBe('/index.php/apps/openregister/api/cases/case-1')
		expect(url).not.toMatch(/apps\/dossiq/)
		expect(url).not.toMatch(/cmmn-plan/)
	})
})

describe('transitionPlanItem', () => {
	it('posts the target state to the item transition route', async () => {
		const { transitionPlanItem } = await importApi()
		axios.post.mockResolvedValue({ data: {} })

		await transitionPlanItem('u-controle', 'completed', 'done')

		expect(axios.post.mock.calls[0][0]).toBe(
			'/index.php/apps/openregister/api/cases/items/u-controle/transition',
		)
		expect(axios.post.mock.calls[0][1]).toEqual({ to: 'completed', reason: 'done' })
	})
})

describe('enablePlanItem', () => {
	it('posts to the item enable route', async () => {
		const { enablePlanItem } = await importApi()
		axios.post.mockResolvedValue({ data: {} })

		await enablePlanItem('u-advies')

		expect(axios.post.mock.calls[0][0]).toBe(
			'/index.php/apps/openregister/api/cases/items/u-advies/enable',
		)
	})
})

describe('fetchEnableableItems', () => {
	it('unwraps the results list', async () => {
		const { fetchEnableableItems } = await importApi()
		axios.get.mockResolvedValue({ data: { results: [{ key: 'extra-advies' }] } })

		expect(await fetchEnableableItems('case-1')).toEqual([{ key: 'extra-advies' }])
	})

	it('answers an empty list when the response carries none', async () => {
		const { fetchEnableableItems } = await importApi()
		axios.get.mockResolvedValue({ data: {} })

		expect(await fetchEnableableItems('case-1')).toEqual([])
	})
})

describe('groupPlanByStage', () => {
	it('nests children under their stage, both ordered by position', async () => {
		const { groupPlanByStage } = await importApi()
		const roots = groupPlanByStage(PLAN.items)

		expect(roots.map((node) => node.key)).toEqual(['intake', 'besluit'])
		expect(roots[0].children.map((node) => node.key)).toEqual(['controle', 'extra-advies'])
		expect(roots[1].children).toEqual([])
	})

	it('surfaces an item whose parent is not in the response, rather than dropping it', async () => {
		const { groupPlanByStage } = await importApi()
		const roots = groupPlanByStage([
			{ id: 9, key: 'orphan', parentItemId: 404, position: 0 },
		])

		expect(roots.map((node) => node.key)).toEqual(['orphan'])
	})

	it('answers an empty list for a missing items list', async () => {
		const { groupPlanByStage } = await importApi()
		expect(groupPlanByStage(undefined)).toEqual([])
	})
})

describe('hasPlanRows', () => {
	it('is false for a plan with no items, which is not the same as a plan', async () => {
		const { hasPlanRows } = await importApi()

		expect(hasPlanRows({ items: [] })).toBe(false)
		expect(hasPlanRows(null)).toBe(false)
		expect(hasPlanRows({})).toBe(false)
	})

	it('is true as soon as OpenRegister holds one row', async () => {
		const { hasPlanRows } = await importApi()
		expect(hasPlanRows(PLAN)).toBe(true)
	})
})

describe('offeredTransitions', () => {
	it('offers nothing on a terminal item', async () => {
		const { offeredTransitions } = await importApi()

		expect(offeredTransitions({ state: 'completed', type: 'humanTask' })).toEqual([])
		expect(offeredTransitions({ state: 'terminated', type: 'humanTask' })).toEqual([])
		expect(offeredTransitions({ state: 'disabled', type: 'humanTask' })).toEqual([])
	})

	it('keeps the milestone asymmetry: available goes to completed or terminated, nothing else moves', async () => {
		const { offeredTransitions } = await importApi()

		expect(offeredTransitions({ state: 'available', type: 'milestone' })).toEqual(['completed', 'terminated'])
		expect(offeredTransitions({ state: 'enabled', type: 'milestone' })).toEqual([])
	})

	it('offers complete and stop on an active task, stop alone before it starts', async () => {
		const { offeredTransitions } = await importApi()

		expect(offeredTransitions({ state: 'active', type: 'humanTask' })).toEqual(['completed', 'terminated'])
		expect(offeredTransitions({ state: 'enabled', type: 'humanTask' })).toEqual(['terminated'])
	})
})

describe('planErrorMessage', () => {
	it('never words a failure as an empty plan', async () => {
		const { planErrorMessage } = await importApi()

		for (const error of [{}, { response: { status: 500 } }, { response: { status: 403 } }]) {
			expect(planErrorMessage(error)).not.toMatch(/no plan|empty|nothing/i)
		}
	})

	it('names the refusal when the caller may not see the plan', async () => {
		const { planErrorMessage } = await importApi()
		expect(planErrorMessage({ response: { status: 403 } })).toMatch(/not allowed/i)
	})

	it('relays what OpenRegister said, rather than a second local opinion', async () => {
		const { planErrorMessage } = await importApi()
		expect(
			planErrorMessage({
				response: { status: 409, data: { error: "'controle' is completed and cannot go to active." } },
			}),
		).toBe("'controle' is completed and cannot go to active.")
	})

	it('falls back to a message that asks for a retry', async () => {
		const { planErrorMessage } = await importApi()
		expect(planErrorMessage({ response: { status: 500 } })).toMatch(/try again/i)
	})
})
