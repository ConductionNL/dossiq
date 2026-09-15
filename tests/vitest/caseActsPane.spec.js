// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The acts pane on the case page (REQ-TASK-041).
 *
 * `alwaysAvailableActs.spec.js` pins the merging rule. This pins that the rule
 * is ON A PAGE: the component asks both authorities, renders both halves, and
 * marks the always-available ones. A capability that exists only as an
 * endpoint is one nobody can use, and the parity ledger would credit it
 * anyway.
 *
 * 🔑 THE TWO READS ARE SEPARATE ON PURPOSE. A case type declaring no
 * always-available acts still has phase acts, and an unreadable lifecycle
 * provider must not empty a list the case type filled. So one failing read is
 * asserted NOT to take the other half with it.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/** What each endpoint answers, keyed by the part of the url that names it. */
let answers = {}
/** Every url read, so the two authorities can be told apart. */
let reads = []

vi.mock('@nextcloud/axios', () => ({
	default: {
		async get(url) {
			reads.push(url)
			const key = url.includes('available-actions') ? 'phase' : 'always'
			const answer = answers[key]
			if (answer instanceof Error) {
				throw answer
			}
			return { data: answer }
		},
	},
}))

const { default: CaseActsPane } = await import(
	'../../src/components/case/CaseActsPane.vue'
)

/**
 * Mount the pane on a case.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountPane() {
	const wrapper = mount(CaseActsPane, { props: { objectId: 'case-9' } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	reads = []
	answers = {
		phase: { actions: [{ action: 'to-hoorzitting', label: 'Plan de hoorzitting' }] },
		always: {
			alwaysAvailable: [
				{ id: 'withdraw', label: 'Trek de zaak in', available: true },
				{ id: 'escalate', label: 'Escaleer', available: false, reason: 'Onvoldoende rechten' },
			],
		},
	}
})

describe('CaseActsPane', () => {
	it('asks both authorities and renders both halves', async () => {
		const wrapper = await mountPane()

		expect(reads.some((url) => url.includes('available-actions'))).toBe(true)
		expect(reads.some((url) => url.includes('/acts'))).toBe(true)

		expect(wrapper.find('[data-testid="case-acts-pane-act-to-hoorzitting"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-acts-pane-act-withdraw"]').exists()).toBe(true)
	})

	it('marks the always-available half, and only that half', async () => {
		const wrapper = await mountPane()

		expect(wrapper.find('[data-testid="case-acts-pane-always-withdraw"]').exists()).toBe(true)
		// The phase's own act carries no mark: the mark is what tells the two
		// halves apart, and marking both would make it say nothing.
		expect(wrapper.find('[data-testid="case-acts-pane-always-to-hoorzitting"]').exists()).toBe(
			false,
		)
	})

	it('shows a refused act with the guard sentence rather than hiding it', async () => {
		const wrapper = await mountPane()

		expect(wrapper.find('[data-testid="case-acts-pane-act-escalate"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-acts-pane-reason-escalate"]').text()).toBe(
			'Onvoldoende rechten',
		)
	})

	it('keeps one half when the other cannot be read', async () => {
		answers.phase = new Error('available-actions answered 502')
		const wrapper = await mountPane()

		// The case type's acts survive an unreadable lifecycle provider. An
		// empty list would tell the handler there is nothing they can do.
		expect(wrapper.find('[data-testid="case-acts-pane-act-withdraw"]').exists()).toBe(true)
	})

	it('says so when there is nothing to do', async () => {
		answers = { phase: { actions: [] }, always: { alwaysAvailable: [] } }
		const wrapper = await mountPane()

		expect(wrapper.find('[data-testid="case-acts-pane-empty"]').exists()).toBe(true)
	})
})
