// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The intake log as a surface: what the mailbox processed, what decided, and
 * why a message became no case.
 *
 * 🔴 THE ASSERTION THAT CARRIES THIS SPEC IS THE ONE ON `unavailable`. A check
 * nobody made and a check that passed are different facts, and a table that
 * renders an absent SPF header as a blank cell invites a reader to take it for
 * a pass. So the row is asserted on the WORDS it shows and on the class that
 * colours it, not on the value being non-empty.
 *
 * The second is the refusal. The log holds the original of every message the
 * mailbox received, so a reader without the intake role must see a refusal and
 * no table at all, rather than an empty one they could read as "nothing came
 * in".
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

/**
 * A stub that renders its default slot inside a tag.
 *
 * @param {string} name The component name.
 * @param {string} tag  The element to render.
 * @return {object} The stub component.
 */
function boxStub(name, tag = 'div') {
	return {
		name,
		props: {
			type: { type: String, default: '' },
			variant: { type: String, default: '' },
			name: { type: String, default: '' },
			description: { type: String, default: '' },
		},
		emits: ['click'],
		render() {
			return h(
				tag,
				{
					class: name,
					'data-type': this.type,
					onClick: () => this.$emit('click'),
				},
				this.$slots.default
					? this.$slots.default()
					: [this.name, this.description].filter(Boolean),
			)
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcAppContent: boxStub('NcAppContent'),
	NcButton: boxStub('NcButton', 'button'),
	NcEmptyContent: boxStub('NcEmptyContent'),
	NcLoadingIcon: boxStub('NcLoadingIcon', 'span'),
	NcNoteCard: boxStub('NcNoteCard'),
	NcSelect: {
		name: 'NcSelect',
		props: {
			modelValue: { type: Object, default: null },
			options: { type: Array, default: () => [] },
			inputLabel: { type: String, default: '' },
		},
		render() {
			return h('select', { class: 'NcSelect' })
		},
	},
	NcTextField: {
		name: 'NcTextField',
		props: { modelValue: { type: String, default: '' } },
		emits: ['update:modelValue'],
		render() {
			return h('input', {
				class: 'NcTextField',
				value: this.modelValue,
				onInput: (event) =>
					this.$emit('update:modelValue', event.target.value),
			})
		},
	},
}))

const MailIntakeLogView = (
	await import('../../src/views/intake/MailIntakeLogView.vue')
).default

/**
 * One log entry, as the endpoint answers it.
 *
 * @param {object} overrides Fields to override.
 * @return {object} The entry.
 */
function entry(overrides = {}) {
	return {
		'@self': { id: 'entry-1' },
		sender: 'aanvrager@voorbeeld.nl',
		subject: 'Bezwaar tegen het besluit',
		decidingFilter: '',
		spfResult: 'pass',
		dkimResult: 'pass',
		dmarcResult: 'pass',
		threadingResult: 'none',
		outcome: 'case',
		reason: 'Became case ZAAK-2026-0001.',
		junkRule: '',
		...overrides,
	}
}

/**
 * The calls the component made, and the answers it got.
 *
 * @type {Array<{url: string, options: object}>}
 */
let calls = []

/**
 * Answer every read with this payload.
 *
 * @param {object} payload   The body the log endpoint returns.
 * @param {number} status    The status the log endpoint returns.
 * @return {void}
 */
function serve(payload, status = 200) {
	calls = []
	globalThis.fetch = vi.fn(async (url, options = {}) => {
		calls.push({ url: String(url), options })

		return {
			status,
			json: async () => payload,
		}
	})
}

/**
 * Mount the view and let its mounted read settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountLog() {
	const wrapper = mount(MailIntakeLogView)
	await flushPromises()

	return wrapper
}

describe('the intake log', () => {
	beforeEach(() => {
		globalThis.OC = { requestToken: 'token' }
		calls = []
	})

	it('names a check nobody made rather than leaving it blank', async () => {
		serve({
			results: [
				entry({
					spfResult: 'unavailable',
					dmarcResult: 'unavailable',
					dkimResult: 'fail',
				}),
			],
		})

		const row = (await mountLog()).find('[data-testid="intake-log-row-entry-1"]')
		const cells = row.findAll('td')

		expect(cells[3].text()).toBe('not checked')
		expect(cells[3].text()).not.toBe('passed')
		expect(cells[3].classes()).toContain('intake-log__result--unknown')
		expect(cells[5].text()).toBe('not checked')
		expect(cells[4].text()).toBe('failed')
		expect(cells[4].classes()).toContain('intake-log__result--fail')
	})

	it('refuses a reader without the intake role instead of showing an empty log', async () => {
		serve({}, 403)

		const wrapper = await mountLog()

		expect(wrapper.find('[data-testid="intake-log-forbidden"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="intake-log-table"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="intake-log-sender"]').exists()).toBe(
			false,
		)
	})

	it('finds a message by its sender', async () => {
		serve({ results: [entry()] })

		const wrapper = await mountLog()
		await wrapper
			.find('input[data-testid="intake-log-sender"]')
			.setValue('aanvrager@voorbeeld.nl')
		await flushPromises()

		expect(calls.at(-1).url).toContain('sender=aanvrager%40voorbeeld.nl')
	})

	it('names the rule that junked a message', async () => {
		serve({
			results: [
				entry({
					outcome: 'refused',
					junkRule: 'spam-header',
					reason: 'Refused as junk.',
				}),
			],
			junkRules: [
				{
					name: 'spam-header',
					description: 'The mail server marked it as spam in X-Spam-Flag.',
				},
			],
		})

		const row = (await mountLog()).find('[data-testid="intake-log-row-entry-1"]')

		expect(row.findAll('td')[8].text()).toBe(
			'spam-header: The mail server marked it as spam in X-Spam-Flag.',
		)
	})

	it('lets a person correct a wrong junk verdict', async () => {
		serve({ results: [entry({ junkRule: 'spam-header' })] })

		const wrapper = await mountLog()
		await wrapper
			.find('[data-testid="intake-log-not-junk-entry-1"]')
			.trigger('click')
		await flushPromises()

		const correction = calls.find((call) => call.url.includes('/junk'))
		expect(correction).toBeTruthy()
		expect(correction.options.method).toBe('POST')
		expect(JSON.parse(correction.options.body)).toEqual({ junk: false })
	})

	it('offers release on a held message and on nothing else', async () => {
		serve({
			results: [
				entry({ '@self': { id: 'held' }, outcome: 'quarantined' }),
				entry({ '@self': { id: 'filed' }, outcome: 'case' }),
			],
		})

		const wrapper = await mountLog()
		expect(
			wrapper.find('[data-testid="intake-log-release-held"]').exists(),
		).toBe(true)
		expect(
			wrapper.find('[data-testid="intake-log-release-filed"]').exists(),
		).toBe(false)

		await wrapper
			.find('[data-testid="intake-log-release-held"]')
			.trigger('click')
		await flushPromises()

		expect(calls.some((call) => call.url.endsWith('/held/release'))).toBe(true)
	})

	it('says the order the filters ran in', async () => {
		serve({
			results: [entry()],
			filterOrder: ['blocked-sender', 'auto-reply', 'bounce-notification'],
		})

		const wrapper = await mountLog()

		expect(wrapper.find('[data-testid="intake-log-order"]').text()).toContain(
			'blocked-sender, auto-reply, bounce-notification',
		)
	})

	it('does not read an empty log as a mailbox that filtered something', async () => {
		serve({ results: [] })

		const wrapper = await mountLog()
		const empty = wrapper.find('.NcEmptyContent')

		expect(empty.exists()).toBe(true)
		expect(empty.text()).toContain('filtered before it reached the folder')
	})
})
