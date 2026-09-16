// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A refused search reads as a refusal on the Cases page.
 *
 * 🔴 THE STORE IS THE LIBRARY'S, NOT dossiq's. CnIndexPage's self-fetch mode
 * reads `useObjectStore` from `@conduction/nextcloud-vue`, pinia id
 * `conduction-objects`, under the key `dossiq-case`. dossiq's own
 * `src/store/modules/object.js` is a SECOND store under the id `object`, and a
 * surface reading that one would find no error however badly the list failed.
 * The key is asserted below rather than assumed, because reading the wrong
 * store renders nothing, which looks exactly like a search that was fine.
 *
 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({
	default: defineComponent({
		name: 'NcNoteCard',
		props: ['type'],
		render() {
			return h('div', { class: 'note-card-stub', 'data-type': this.type }, this.$slots.default?.())
		},
	}),
}))

/** The library object store the page writes its failures to. Replaced per test. */
let libraryStore = { errors: {} }

vi.mock('@conduction/nextcloud-vue', () => ({
	useObjectStore: () => libraryStore,
}))

const { default: CaseSearchRefusal } =
	await import('../../src/components/search/CaseSearchRefusal.vue')

/**
 * The shape `parseResponseError()` records for a refused term.
 *
 * @param {string} message The refusal message openregister sent.
 * @return {object} The ApiError the object store would hold.
 */
function refusalError(message) {
	return {
		status: 400,
		message,
		details: message,
		isValidation: true,
		fields: null,
	}
}

/**
 * Mount the hint over a given store state and address bar.
 *
 * @param {object}      options        What the page is in.
 * @param {object|null} options.error  The error on `errors['dossiq-case']`.
 * @param {string}      options.search The `_search` in the route query.
 * @param {string}      [options.key]  The store key to file the error under.
 *
 * @return {object} The mounted wrapper.
 */
function mountHint({ error = null, search = '', key = 'dossiq-case' }) {
	libraryStore = { errors: error === null ? {} : { [key]: error } }

	return mount(CaseSearchRefusal, {
		global: {
			mocks: { $route: { query: search === '' ? {} : { _search: search } } },
		},
	})
}

describe('CaseSearchRefusal', () => {
	it('says where the term broke instead of letting the list say nothing matched', () => {
		const wrapper = mountHint({
			error: refusalError('Unbalanced bracket at position 1.'),
			search: '(dakkapel AND NOT geweigerd',
		})

		expect(wrapper.find('[data-testid="case-search-refusal"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-search-refusal-headline"]').text())
			.toBe('We could not read this search from character 1.')
		expect(wrapper.find('[data-testid="case-search-refusal-reason"]').text())
			.toBe('Unbalanced bracket at position 1.')
	})

	it('marks the character the platform pointed at', () => {
		const wrapper = mountHint({
			error: refusalError('Unexpected operator at position 5.'),
			search: 'dak AND',
		})

		expect(wrapper.find('[data-testid="case-search-refusal-term"] mark').text()).toBe('A')
	})

	it('reads the key CnIndexPage files the Cases failure under', () => {
		const wrapper = mountHint({
			error: refusalError('Unbalanced bracket at position 1.'),
			search: '(dakkapel',
			key: 'dossiq-caseType',
		})

		expect(wrapper.find('[data-testid="case-search-refusal"]').exists()).toBe(false)
	})

	it('stays out of the way when the search was fine', () => {
		const wrapper = mountHint({ error: null, search: 'dakkapel' })

		expect(wrapper.find('[data-testid="case-search-refusal"]').exists()).toBe(false)
	})

	it('stays out of the way when the failure was not a refused term', () => {
		const wrapper = mountHint({
			error: { status: 500, message: 'An unexpected server error occurred. Please try again.' },
			search: 'dakkapel',
		})

		expect(wrapper.find('[data-testid="case-search-refusal"]').exists()).toBe(false)
	})

	it('tells the reader what to do next', () => {
		const wrapper = mountHint({
			error: refusalError('Unbalanced bracket at position 1.'),
			search: '(dakkapel',
		})

		expect(wrapper.text()).toContain('AND, OR and NOT work in capitals')
	})
})
