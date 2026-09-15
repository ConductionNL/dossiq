// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The adaptive case-plan panel, over OpenRegister's case layer.
 *
 * The error path is the assertion that matters, and REQ-RCMN-001 words it as
 * a hard requirement: when OpenRegister cannot be reached the panel shows an
 * error and offers a retry. It does NOT render an empty plan, because an
 * outage and a case with no work left look identical from the browser and a
 * caseworker who reads one as the other closes a case with an open advice task
 * still in it. An outage is not reproducible on the shared e2e instance
 * without harming neighbouring specs, so this is where it is pinned.
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@nextcloud/event-bus', () => ({
	emit: vi.fn(),
	subscribe: vi.fn(),
	unsubscribe: vi.fn(),
}))

/**
 * A click-forwarding stand-in for a Nextcloud component.
 *
 * @param {string} name The component name.
 * @param {string} tag  The element to render.
 * @return {object} The stub component.
 */
function stub(name, tag = 'div') {
	return defineComponent({
		name,
		emits: ['click'],
		render() {
			return h(
				tag,
				{ onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	})
}

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: stub('NcButton', 'button'),
}))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({
	default: stub('NcLoadingIcon'),
}))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({
	default: defineComponent({
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		render() {
			return h('div', {}, [
				h('h3', {}, this.name),
				h('p', {}, this.description),
				this.$slots.action?.(),
			])
		},
	}),
}))
vi.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({
	default: stub('AlertCircleOutline'),
}))

/** What `fetchCasePlan` answers. Replaced per test. */
let planAnswer = vi.fn()
/** What the retiring engine answers. Replaced per test. */
let localPlanAnswer = vi.fn()
/** What the object store answers for the case record. Replaced per test. */
let caseAnswer = {}
/** Whether this instance prefers OpenRegister rows. Replaced per test. */
let prefer = true
/** Every transition the panel asked for. */
let transitions = []

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app, _key, fallback) => (prefer === null ? fallback : prefer),
}))

vi.mock('../../src/store/store.js', () => ({ initializeStores: async () => ({}) }))

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({ fetchObject: async () => caseAnswer }),
}))

vi.mock('../../src/services/cmmnApi.js', () => ({
	fetchCasePlan: (...args) => localPlanAnswer(...args),
	completeTask: (...args) => {
		transitions.push(['local-complete', ...args])
		return Promise.resolve({})
	},
	terminateTask: (...args) => {
		transitions.push(['local-terminate', ...args])
		return Promise.resolve({})
	},
	enableDiscretionaryItem: (...args) => {
		transitions.push(['local-enable', ...args])
		return Promise.resolve({})
	},
	signalCaseFileEvent: () => Promise.resolve({}),
}))

vi.mock('../../src/services/casePlanApi.js', async () => {
	const actual = await vi.importActual('../../src/services/casePlanApi.js')
	return {
		...actual,
		fetchCasePlan: (...args) => planAnswer(...args),
		transitionPlanItem: (...args) => {
			transitions.push(args)
			return Promise.resolve({})
		},
		enablePlanItem: (...args) => {
			transitions.push(['enable', ...args])
			return Promise.resolve({})
		},
		planErrorMessage: () => 'The case plan could not be loaded. Try again.',
	}
})

const { default: CasePlanPanel } =
	await import('../../src/components/case/CasePlanPanel.vue')

const PLAN = {
	items: [
		{
			id: 1,
			uuid: 'u-intake',
			key: 'intake',
			name: 'Intake',
			type: 'stage',
			parentItemId: null,
			position: 0,
			state: 'active',
		},
		{
			id: 2,
			uuid: 'u-controle',
			key: 'controle',
			name: 'Controle',
			type: 'humanTask',
			parentItemId: 1,
			position: 0,
			state: 'active',
		},
	],
}

/**
 * Mount the panel for one case.
 *
 * @return {Promise<object>} The mounted wrapper, after its load settles.
 */
async function mountPanel() {
	const wrapper = mount(CasePlanPanel, {
		props: { objectId: 'case-1' },
		global: {
			mocks: { t: (_app, text) => text, $route: { params: { id: 'case-1' } } },
		},
	})
	await flushPromises()
	return wrapper
}

/** The same plan as the retiring engine answers it: string ids, `parentId`. */
const LOCAL_PLAN = {
	items: [
		{
			id: 'intake',
			name: 'Intake',
			type: 'stage',
			parentId: null,
			state: 'active',
			discretionary: false,
		},
		{
			id: 'controle',
			name: 'Controle',
			type: 'humanTask',
			parentId: 'intake',
			state: 'active',
			discretionary: false,
		},
	],
	enableableDiscretionary: [],
	milestones: {},
	caseFile: {},
}

beforeEach(() => {
	transitions = []
	planAnswer = vi.fn()
	localPlanAnswer = vi.fn().mockResolvedValue(LOCAL_PLAN)
	caseAnswer = {}
	prefer = true
})

describe('CasePlanPanel', () => {
	it('renders the plan OpenRegister holds, grouped by stage', async () => {
		planAnswer.mockResolvedValue(PLAN)
		const wrapper = await mountPanel()

		expect(wrapper.text()).toContain('Intake')
		expect(wrapper.text()).toContain('Controle')
		expect(wrapper.find('[data-testid="case-plan-error"]').exists()).toBe(false)
	})

	it('fails closed when OpenRegister is unreachable: an error with a retry, never an empty plan', async () => {
		planAnswer.mockRejectedValue({ response: { status: 500 } })
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="case-plan-error"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-plan-retry"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-plan-empty"]').exists()).toBe(false)
		expect(wrapper.text()).not.toMatch(/no adaptive plan/i)
	})

	it('retries the read when the caseworker asks it to', async () => {
		planAnswer
			.mockRejectedValueOnce({ response: { status: 500 } })
			.mockResolvedValue(PLAN)
		const wrapper = await mountPanel()

		await wrapper.find('[data-testid="case-plan-retry"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="case-plan-error"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('Controle')
	})

	it('reads a 404 as "OpenRegister holds no plan", not as an outage', async () => {
		planAnswer.mockRejectedValue({ response: { status: 404 } })
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="case-plan-error"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="case-plan-empty"]').exists()).toBe(true)
	})

	it('sends a completion to OpenRegister rather than judging it locally', async () => {
		planAnswer.mockResolvedValue(PLAN)
		const wrapper = await mountPanel()

		await wrapper
			.find('[data-testid="case-plan-completed-controle"]')
			.trigger('click')
		await flushPromises()

		expect(transitions).toEqual([['u-controle', 'completed']])
	})
})

describe('CasePlanPanel read preference', () => {
	it('reads OpenRegister when it has rows, even though the case still carries a blob', async () => {
		planAnswer.mockResolvedValue(PLAN)
		localPlanAnswer = vi.fn()
		caseAnswer = { casePlanState: '{"planItemStates":{"intake":"active"}}' }

		const wrapper = await mountPanel()

		expect(localPlanAnswer).not.toHaveBeenCalled()
		expect(wrapper.vm.source).toBe('openregister')
	})

	it('falls back to the local engine while a blob is present and OpenRegister holds nothing', async () => {
		planAnswer.mockRejectedValue({ response: { status: 404 } })
		caseAnswer = { casePlanState: '{"planItemStates":{"intake":"active"}}' }

		const wrapper = await mountPanel()

		expect(localPlanAnswer).toHaveBeenCalledWith('case-1')
		expect(wrapper.vm.source).toBe('local')
		expect(wrapper.text()).toContain('Controle')
	})

	it('sends a local completion to the engine, not to OpenRegister', async () => {
		planAnswer.mockRejectedValue({ response: { status: 404 } })
		caseAnswer = { casePlanState: '{"planItemStates":{"intake":"active"}}' }

		const wrapper = await mountPanel()
		await wrapper
			.find('[data-testid="case-plan-completed-controle"]')
			.trigger('click')
		await flushPromises()

		expect(transitions).toEqual([['local-complete', 'case-1', 'controle']])
	})

	it('reads neither runtime when the case has no rows and no blob', async () => {
		planAnswer.mockRejectedValue({ response: { status: 404 } })
		caseAnswer = { casePlanState: '' }

		const wrapper = await mountPanel()

		expect(localPlanAnswer).not.toHaveBeenCalled()
		expect(wrapper.vm.source).toBe('none')
		expect(wrapper.find('[data-testid="case-plan-empty"]').exists()).toBe(true)
	})

	it('goes back to the engine when the flag is off and the case has both', async () => {
		planAnswer.mockResolvedValue(PLAN)
		caseAnswer = { casePlanState: '{"planItemStates":{"intake":"active"}}' }
		prefer = false

		const wrapper = await mountPanel()

		expect(localPlanAnswer).toHaveBeenCalledWith('case-1')
		expect(wrapper.vm.source).toBe('local')
	})
})
