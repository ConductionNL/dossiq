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
/** What the object store answers for the case record. Replaced per test. */
let caseAnswer = {}
/** Every transition the panel asked for. */
let transitions = []

vi.mock('../../src/store/store.js', () => ({ initializeStores: async () => ({}) }))

/** What the object store answers for the case's caseType. Replaced per test. */
let caseTypeAnswer = null
/** Every caseType id the panel read. */
let caseTypeReads = []

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		fetchObject: async (type, id) => {
			if (type === 'caseType') {
				caseTypeReads.push(id)
				return caseTypeAnswer
			}
			return caseAnswer
		},
	}),
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

beforeEach(() => {
	transitions = []
	planAnswer = vi.fn()
	caseAnswer = {}
	caseTypeAnswer = null
	caseTypeReads = []
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

describe('CasePlanPanel has one runtime', () => {
	// The local engine is gone (retire-cmmn-caseplanstate group 3). A case
	// OpenRegister holds nothing for has no plan, whatever else it carries.

	it('shows no plan when OpenRegister holds none, and asks nothing else', async () => {
		planAnswer.mockRejectedValue({ response: { status: 404 } })
		caseAnswer = { caseType: 'ct-cmmn' }
		caseTypeAnswer = { id: 'ct-cmmn', handlingModel: 'cmmn' }

		const wrapper = await mountPanel()

		expect(planAnswer).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-testid="case-plan-empty"]').exists()).toBe(true)
	})

	it('reads an empty item list as no plan', async () => {
		planAnswer.mockResolvedValue({ items: [] })

		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="case-plan-empty"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-plan-error"]').exists()).toBe(false)
	})
})

describe('CasePlanPanel asks OpenRegister only when it can hold a plan', () => {
	// Round 5: every case page fired GET /apps/openregister/api/cases/{id} and
	// got a 404, because dossiq projects a plan only for a CMMN caseType and
	// asked for one on every case. These pin that a BPMN case no longer asks,
	// and that every case that might have rows still does.

	it('does not ask OpenRegister for the plan of a BPMN case', async () => {
		planAnswer.mockRejectedValue({ response: { status: 404 } })
		caseAnswer = { caseType: 'ct-bpmn' }
		caseTypeAnswer = { id: 'ct-bpmn', handlingModel: 'bpmn' }

		const wrapper = await mountPanel()

		expect(caseTypeReads).toEqual(['ct-bpmn'])
		expect(planAnswer).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="case-plan-empty"]').exists()).toBe(true)
	})

	it('reads a caseType without a handlingModel as BPMN, as the projection does', async () => {
		caseAnswer = { caseType: 'ct-old' }
		caseTypeAnswer = { id: 'ct-old' }

		await mountPanel()

		expect(planAnswer).not.toHaveBeenCalled()
	})

	it('asks OpenRegister for the plan of a CMMN case', async () => {
		planAnswer.mockResolvedValue(PLAN)
		caseAnswer = { caseType: 'ct-cmmn' }
		caseTypeAnswer = { id: 'ct-cmmn', handlingModel: 'cmmn' }

		const wrapper = await mountPanel()

		expect(planAnswer).toHaveBeenCalledWith('case-1')
		expect(wrapper.text()).toContain('Controle')
	})

	it('still asks when the caseType cannot be read, so an outage stays visible', async () => {
		planAnswer.mockRejectedValue({ response: { status: 500 } })
		caseAnswer = { caseType: 'ct-unknown' }
		caseTypeAnswer = null

		const wrapper = await mountPanel()

		expect(planAnswer).toHaveBeenCalled()
		expect(wrapper.find('[data-testid="case-plan-error"]').exists()).toBe(true)
	})
})
