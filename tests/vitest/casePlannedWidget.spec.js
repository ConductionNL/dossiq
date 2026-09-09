// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A planned follow-up reaches the Related tab, and a read that fails says so.
 *
 * WHAT THIS PINS DOWN, AND WHAT IT DELIBERATELY DOES NOT
 * -----------------------------------------------------
 * A planned follow-up is a scheduled flow rather than a case, so the library's
 * related widget cannot fetch it. `CasePlannedWidget` reads it from dossiq's
 * own endpoint and hands it over as an `extraSections` group. That handover is
 * the whole of dossiq's half, and it is what these tests cover.
 *
 * They do NOT assert that the library then renders the group. The vitest suite
 * aliases `@conduction/nextcloud-vue` to a stub, so a passing render here would
 * be a test of the stub. `e2e/case-actions-menu.spec.ts:321` is where the
 * rendered row is claimed.
 *
 * WHY THE FAILING-READ TEST EXISTS
 * --------------------------------
 * `load()` ended in a bare `catch` that set the list empty and said nothing.
 * A follow-up that failed to load and a case with no follow-ups then produced
 * byte-identical output: an empty group and a quiet console. That was measured
 * from the other side on a running instance, where the endpoint answered 200
 * with the row and the tab showed no row at all — and the swallowed catch is
 * the reason the browser could not tell which of the two had happened.
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { default: CasePlannedWidget } =
	await import('../../src/components/case/CasePlannedWidget.vue')

const PLANNED = {
	id: 'flow-1',
	title: 'Controle',
	date: '2026-10-09',
	caseType: 'ct-1',
}

/**
 * Mount the widget on a case.
 *
 * @return {object} The Vue Test Utils wrapper.
 */
function mountWidget() {
	return mount(CasePlannedWidget, {
		props: { objectId: 'case-1', register: 'dossiq', schema: 'case' },
		global: {
			stubs: {
				NcButton: { template: '<button><slot /></button>' },
				CalendarClock: true,
				CasePlanFollowUpDialog: true,
			},
		},
	})
}

describe('CasePlannedWidget', () => {
	let errors

	beforeEach(() => {
		axios.get.mockReset()
		errors = []
		vi.spyOn(console, 'error').mockImplementation((...args) => {
			errors.push(args.map(String).join(' '))
		})
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('hands a planned follow-up to the related widget as an extra section', async () => {
		axios.get.mockResolvedValue({ data: { results: [PLANNED], total: 1 } })

		const wrapper = mountWidget()
		await flushPromises()

		// The endpoint, with the case in the path. Asserted because the widget
		// reads it on an `objectId` watcher: an id that never arrives produces
		// the same empty group as a case with nothing planned.
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(String(axios.get.mock.calls[0][0])).toContain(
			'/apps/dossiq/api/case/case-1/planned',
		)

		const group = wrapper.find('.cn-related-objects-widget__group')
		expect(group.exists()).toBe(true)
		// The title AND the date. A row carrying the title alone would satisfy
		// a `toContain('Controle')` and still not tell a handler when the
		// follow-up is due, which is the half that makes it a plan.
		expect(group.find('li').text()).toContain('Controle')
		expect(group.find('li').text()).toContain('2026-10-09')
	})

	it('renders no group heading for a case with nothing planned', async () => {
		axios.get.mockResolvedValue({ data: { results: [], total: 0 } })

		const wrapper = mountWidget()
		await flushPromises()

		// The section exists and is silent. An empty `items` renders no
		// heading, so a case with no follow-up looks as it did before this
		// widget existed.
		expect(wrapper.find('.cn-related-objects-widget__group h4').exists()).toBe(
			false,
		)
		expect(wrapper.findAll('li')).toHaveLength(0)
		expect(errors).toHaveLength(0)
	})

	it('reports a read that failed instead of showing an empty list', async () => {
		axios.get.mockRejectedValue(new Error('boom'))

		const wrapper = mountWidget()
		await flushPromises()

		// The tab still renders: the planned group is additive, and hiding the
		// related content because one extra section could not load would cost
		// more than it saves.
		expect(
			wrapper.find('[data-testid="cn-related-objects-widget"]').exists(),
		).toBe(true)
		expect(wrapper.findAll('li')).toHaveLength(0)

		// But it is not silent. Without this, a failed read and a case with no
		// follow-ups are the same observation, which is exactly what made the
		// live symptom unreadable.
		expect(errors.join(' ')).toMatch(/planned follow-ups/i)
		expect(errors.join(' ')).toMatch(/case-1/)
	})
})
