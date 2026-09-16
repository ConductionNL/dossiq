// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A reviewer's own pending archival decisions, in My Work.
 *
 * Two things are pinned here that a browser test on a shared instance cannot
 * pin without harming its neighbours. First, an empty list and a failed read
 * are drawn differently: they are the same empty array, and only one of them
 * means somebody's work is invisible. Second, the answer is refused before it
 * is sent when it would be refused after: openregister answers 400 without a
 * reason, and without a new date on a retention.
 *
 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

/**
 * A click-forwarding stand-in for a Nextcloud component.
 *
 * @param {string} name The component name.
 * @param {string} tag The element to render.
 * @return {object} The stub component.
 */
function stub(name, tag = 'div') {
	return defineComponent({
		name,
		props: ['disabled', 'modelValue', 'label', 'options', 'inputLabel', 'type', 'name', 'description'],
		emits: ['click', 'update:modelValue'],
		render() {
			return h(
				tag,
				{ onClick: () => this.$emit('click'), disabled: this.disabled },
				this.$slots.default?.(),
			)
		},
	})
}

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: stub('NcButton', 'button') }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: stub('NcLoadingIcon') }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: stub('NcSelect') }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: stub('NcTextField', 'input') }))
vi.mock('@nextcloud/vue/components/NcDateTimePickerNative', () => ({
	default: stub('NcDateTimePickerNative', 'input'),
}))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({
	default: defineComponent({
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		render() {
			return h('div', {}, [h('h3', {}, this.name), this.$slots.action?.()])
		},
	}),
}))
vi.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({ default: stub('AlertCircleOutline') }))
vi.mock('vue-material-design-icons/ArchiveOutline.vue', () => ({ default: stub('ArchiveOutline') }))

/** What the worklist read answers. Replaced per test. */
let pendingAnswer = vi.fn()
/** Every decision the section posted. */
let decisions = []

vi.mock('../../src/services/archivalApi.js', () => ({
	ANSWERS: ['destroy', 'retain', 'transfer'],
	pendingReviews: (...args) => pendingAnswer(...args),
	decide: (payload) => {
		decisions.push(payload)
		return Promise.resolve({})
	},
}))

const { default: MyArchivalReviews } = await import(
	'../../src/components/case/MyArchivalReviews.vue'
)

/**
 * Mount the section and let its read settle.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountSection() {
	const wrapper = mount(MyArchivalReviews, {
		global: { mocks: { t: (_app, s) => s } },
	})
	await flushPromises()
	return wrapper
}

const ENTRY = {
	entryId: 'record-9',
	listId: 'list-3',
	title: 'Omgevingsvergunning 2019-0142',
	archiefactiedatum: '2026-10-01',
}

describe('MyArchivalReviews', () => {
	beforeEach(() => {
		decisions = []
		pendingAnswer = vi.fn().mockResolvedValue([ENTRY])
	})

	it('lists what the endpoint answered, without narrowing it', async () => {
		const wrapper = await mountSection()

		expect(pendingAnswer).toHaveBeenCalledWith()
		expect(wrapper.find('[data-testid="archival-review-record-9"]').exists()).toBe(true)
	})

	it('says there is nothing to sign off on an empty list', async () => {
		pendingAnswer = vi.fn().mockResolvedValue([])

		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="archival-reviews-empty"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="archival-reviews-error"]').exists()).toBe(false)
	})

	it('draws a failed read as an error with a retry, not as an empty list', async () => {
		pendingAnswer = vi.fn().mockRejectedValue(new Error('openregister is unreachable'))

		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="archival-reviews-error"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="archival-reviews-retry"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="archival-reviews-empty"]').exists()).toBe(false)
	})

	it('refuses an answer with no reason, and posts nothing', async () => {
		const wrapper = await mountSection()

		wrapper.vm.setAnswer(ENTRY, 'destroy')
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.canAnswer(ENTRY)).toBe(false)

		await wrapper.vm.answer(ENTRY)

		expect(decisions).toEqual([])
	})

	it('refuses a retention with no new date, and posts nothing', async () => {
		const wrapper = await mountSection()

		wrapper.vm.setAnswer(ENTRY, 'retain')
		wrapper.vm.setReason(ENTRY, 'the bezwaar is still running')
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.canAnswer(ENTRY)).toBe(false)

		wrapper.vm.setDate(ENTRY, '2031-10-01')
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.canAnswer(ENTRY)).toBe(true)
	})

	it('records a destruction with its reason and drops the entry from the list', async () => {
		const wrapper = await mountSection()

		wrapper.vm.setAnswer(ENTRY, 'destroy')
		wrapper.vm.setReason(ENTRY, 'de bewaartermijn is verstreken')
		await wrapper.vm.answer(ENTRY)
		await wrapper.vm.$nextTick()

		expect(decisions).toEqual([{
			listId: 'list-3',
			entryId: 'record-9',
			answer: 'destroy',
			reason: 'de bewaartermijn is verstreken',
			newArchiefactiedatum: null,
		}])
		expect(wrapper.find('[data-testid="archival-review-record-9"]').exists()).toBe(false)
	})

	it('sends the new date only on a retention', async () => {
		const wrapper = await mountSection()

		wrapper.vm.setAnswer(ENTRY, 'retain')
		wrapper.vm.setReason(ENTRY, 'de zaak loopt nog')
		wrapper.vm.setDate(ENTRY, '2031-10-01')
		await wrapper.vm.answer(ENTRY)

		expect(decisions[0].newArchiefactiedatum).toBe('2031-10-01')
	})
})
