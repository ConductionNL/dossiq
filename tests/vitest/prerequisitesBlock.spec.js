// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Prerequisites block shows what the SERVER read, present and missing.
 *
 * THE ASSERTION THAT CARRIES THE REQUIREMENT is "an item the server reported
 * absent renders as missing". A block that only proved it can list things
 * would pass against a component that drew every row the same way, which is
 * the whole failure this change exists to end: an administrator learned of a
 * missing extension from a stack trace, and a block that says "present" about
 * everything is no better than the stack trace.
 *
 * The state is mocked rather than the DOM seeded, because `loadState` reads a
 * script tag the settings framework writes, and a component that read the DOM
 * directly is the pattern ADR-004 forbids.
 *
 * @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

/** The state the component is mounted against, swapped per test. */
let state = {}

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => (state === null ? fallback : state),
}))

vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({
	default: {
		name: 'NcNoteCard',
		props: { type: { type: String, default: '' } },
		render() {
			return h(
				'div',
				{ class: 'nc-note-card', 'data-type': this.type },
				this.$slots.default ? this.$slots.default() : [],
			)
		},
	},
}))

const PrerequisitesTab = (
	await import('../../src/views/settings/tabs/PrerequisitesTab.vue')
).default

/**
 * A server reading, in the shape `Prerequisites::check()` answers with.
 *
 * @param {object} overrides Blocks to replace.
 * @return {object} The reading.
 */
function reading(overrides = {}) {
	return {
		php: { required: '8.3', running: '8.3.6', present: true },
		nextcloud: { min: '32', max: '34' },
		extensions: [
			{ name: 'json', why: 'Reads every payload.', present: true },
			{ name: 'mbstring', why: 'Counts text.', present: true },
			{ name: 'zip', why: 'Packs an export.', present: true },
		],
		apps: {
			required: [
				{ id: 'openregister', unlocks: 'Stores every case.', present: true },
			],
			optional: [
				{ id: 'hrmq', unlocks: 'Books the hours.', present: false },
				{ id: 'hermiq', unlocks: 'Answers the AI steps.', present: true },
			],
		},
		...overrides,
	}
}

/**
 * Mount the block against one reading.
 *
 * @param {object|null} value The reading, or null for no state at all.
 * @return {object} The mounted wrapper.
 */
function open(value) {
	state = value
	return mount(PrerequisitesTab)
}

/**
 * The text of one row.
 *
 * @param {object} wrapper The mounted wrapper.
 * @param {string} testid  The row's data-testid.
 * @return {string} Its text.
 */
function row(wrapper, testid) {
	const found = wrapper.find(`[data-testid="${testid}"]`)
	expect(found.exists(), `${testid} must be rendered`).toBe(true)
	return found.text()
}

describe('The Prerequisites block', () => {
	it('says missing about an extension the server reported absent', () => {
		// 🔴 THE ONE THIS BLOCK EXISTS FOR. Both answers are asked for in the
		// same mount, so a component that drew every row identically fails
		// here rather than passing on the half it happens to agree with.
		const wrapper = open(
			reading({
				extensions: [
					{ name: 'json', why: 'Reads every payload.', present: true },
					{ name: 'zip', why: 'Packs an export.', present: false },
				],
			}),
		)

		expect(row(wrapper, 'prerequisite-ext-zip')).toContain('missing')
		expect(row(wrapper, 'prerequisite-ext-json')).toContain('present')
	})

	it('says what each extension is for, so a reader can act on it', () => {
		const wrapper = open(reading())
		expect(row(wrapper, 'prerequisite-ext-zip')).toContain('Packs an export.')
	})

	it('lists an absent optional app with what it would have added', () => {
		const wrapper = open(reading())
		const hours = row(wrapper, 'prerequisite-app-hrmq')

		expect(hours).toContain('missing')
		expect(hours).toContain('Books the hours.')
		expect(row(wrapper, 'prerequisite-app-hermiq')).toContain('present')
	})

	it('says out loud when the app dossiq cannot run without is absent', () => {
		const wrapper = open(
			reading({
				apps: {
					required: [
						{
							id: 'openregister',
							unlocks: 'Stores every case.',
							present: false,
						},
					],
					optional: [],
				},
			}),
		)

		const blocking = wrapper.find('[data-testid="prerequisites-blocking"]')
		expect(blocking.exists()).toBe(true)
		expect(blocking.text()).toContain('openregister')
	})

	it('stays quiet about blocking when everything required is there', () => {
		const wrapper = open(reading())
		expect(wrapper.find('[data-testid="prerequisites-blocking"]').exists()).toBe(
			false,
		)
	})

	it('names the running PHP beside the one that is required', () => {
		const wrapper = open(reading())
		const php = row(wrapper, 'prerequisite-php')

		// Both numbers, because "PHP 8.3 or higher" on its own tells a reader
		// nothing about the instance they are looking at.
		expect(php).toContain('8.3')
		expect(php).toContain('8.3.6')
	})

	it('renders an empty block rather than throwing on absent state', () => {
		// A dozen other sections share this page. One of them failing to read
		// its state must not take the rest of the page with it.
		const wrapper = open(null)
		expect(wrapper.find('[data-testid="prerequisites-block"]').exists()).toBe(
			true,
		)
	})
})
