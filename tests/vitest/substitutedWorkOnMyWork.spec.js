// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Substituted work on My work: the rows are merged, marked and hideable.
 *
 * WHAT THIS FILE IS FOR. `src/utils/substitutionHelpers.js` and
 * `fetchSubstitutedWork()` shipped with no call site: the helpers were
 * imported by nothing and neither My work surface mentioned substitution, so
 * a waarnemer saw only their own work and nothing said otherwise. A unit test
 * over the helpers alone could not have caught that, because the helpers were
 * correct the whole time. So the assertions here are on the two VIEWS, over a
 * stubbed `/api/substitutions/work`, plus one mechanical check that every
 * export of the helper module is reached from one of them.
 *
 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const HERE = dirname(fileURLToPath(import.meta.url))
const ROOT = resolve(HERE, '../..')

/** What the stubbed `/api/substitutions/work` answers, replaced per test. */
let substituted = { cases: [], tasks: [] }
/** How many times the views asked for it. */
let fetchCalls = 0

vi.mock('../../src/services/substitutionApi.js', () => ({
	fetchSubstitutedWork: async () => {
		fetchCalls += 1
		if (substituted instanceof Error) {
			throw substituted
		}
		return substituted
	},
}))

/** The engine rows the tile's own store answers with. */
let ownTasks = []

vi.mock('../../src/store/modules/engineTask.js', async (importOriginal) => ({
	...(await importOriginal()),
	useEngineTaskStore: () => ({
		error: null,
		async list() {
			return ownTasks
		},
	}),
}))

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'marieke' }),
}))

vi.mock('../../src/store/store.js', () => ({
	initializeStores: vi.fn(() => Promise.resolve()),
}))

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		fetchCollection: vi.fn(() => Promise.resolve([])),
	}),
}))

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(() => Promise.reject(new Error('not stubbed'))) },
}))

/**
 * CnIndexPage, rendering its slots so the substituted group is reachable.
 *
 * The real component self-fetches the reader's own cases from OpenRegister;
 * what matters here is that the group the view puts in `before-collection`
 * renders, so the stub renders exactly the slots the view fills.
 */
vi.mock('@conduction/nextcloud-vue', () => ({
	CnIndexPage: defineComponent({
		name: 'CnIndexPage',
		render() {
			return h('div', { class: 'cn-index-page-stub' }, [
				this.$slots['below-header']?.(),
				this.$slots['before-collection']?.(),
			])
		},
	}),
	CnDataTable: defineComponent({
		name: 'CnDataTable',
		props: {
			rows: { type: Array, default: () => [] },
			columns: { type: Array, default: () => [] },
		},
		render() {
			return h('div', { class: 'cn-data-table-stub' }, [
				h(
					'div',
					{ class: 'cn-columns' },
					this.columns.map((c) => c.key).join(','),
				),
				...this.rows.map((row) =>
					h('div', { class: 'cn-row' }, [
						h('span', { class: 'cn-row__title' }, String(row.title ?? '')),
						h(
							'span',
							{ class: 'cn-row__marker' },
							String(row.substitutedMarker ?? ''),
						),
						h('span', { class: 'cn-row__days' }, String(row.daysLeft ?? '')),
					]),
				),
				this.$slots.footer?.(),
			])
		},
	}),
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: defineComponent({
		name: 'NcButton',
		emits: ['click'],
		render() {
			return h(
				'button',
				{ onClick: () => this.$emit('click') },
				this.$slots.default ? this.$slots.default() : [],
			)
		},
	}),
}))

const { default: MyWorkCards } = await import('../../src/views/MyWorkCards.vue')
const { default: MyWorkWidget } = await import(
	'../../src/views/widgets/MyWorkWidget.vue'
)

/**
 * One substituted case, as `/api/substitutions/work` answers it.
 *
 * @param {object} over Fields to override.
 * @return {object} The row.
 */
function substitutedCase(over = {}) {
	return {
		id: 'c-1',
		title: 'Bezwaar Jansen',
		identifier: 'ZAAK-2026-0001',
		_substituted: {
			absentee: 'anna',
			substitutionId: 'sub-1',
			until: '2026-07-21',
		},
		...over,
	}
}

/**
 * Mount the My work index.
 *
 * @return {Promise<object>} The mounted wrapper, after its loads settle.
 */
async function mountIndex() {
	const wrapper = mount(MyWorkCards, {
		global: {
			mocks: { $router: { push: vi.fn() } },
			stubs: { WorkloadSummaryBar: true },
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * Mount the My work tile.
 *
 * @return {Promise<object>} The mounted wrapper, after its loads settle.
 */
async function mountTile() {
	const wrapper = mount(MyWorkWidget, {
		props: { widget: { content: { limit: 10 } }, item: {} },
		global: {
			stubs: { RouterLink: { template: '<a><slot /></a>' } },
			mocks: { $router: { push: vi.fn() } },
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	substituted = { cases: [], tasks: [] }
	ownTasks = []
	fetchCalls = 0
	try {
		window.localStorage.clear()
	} catch {
		// jsdom always has it; a profile that does not simply keeps the default.
	}
})

describe('the My work index', () => {
	it('asks the resolver for the work an active substitution routes here', async () => {
		await mountIndex()
		expect(fetchCalls).toBe(1)
	})

	it('lists a substituted case marked with the absentee and the end date', async () => {
		substituted = { cases: [substitutedCase()], tasks: [] }
		const wrapper = await mountIndex()

		const marker = wrapper.find('[data-testid="substituted-marker"]')
		expect(marker.exists()).toBe(true)
		// The absentee by name, not merely "something non-empty": the
		// requirement names whose work it is, and a marker reading anything
		// else is the wrong marker on the right row.
		expect(marker.text()).toContain('anna')
		expect(marker.text()).toContain('2026-07-21')
	})

	it('removes the substituted rows when they are hidden, and keeps the way back', async () => {
		substituted = { cases: [substitutedCase()], tasks: [] }
		const wrapper = await mountIndex()
		expect(wrapper.findAll('[data-testid="substituted-marker"]')).toHaveLength(1)

		await wrapper.find('[data-testid="substituted-toggle"]').trigger('click')
		await flushPromises()

		expect(wrapper.findAll('[data-testid="substituted-marker"]')).toHaveLength(0)
		// The toggle itself must survive being used, or hiding is one-way.
		expect(wrapper.find('[data-testid="substituted-toggle"]').exists()).toBe(
			true,
		)
		expect(window.localStorage.getItem('dossiq:my-work:show-substituted')).toBe(
			'false',
		)
	})

	it('shows no group at all when nothing is routed here', async () => {
		const wrapper = await mountIndex()
		expect(wrapper.find('[data-testid="substituted-work"]').exists()).toBe(false)
	})

	it('renders the reader own list when the resolver refuses', async () => {
		// A 403 or a 500 on the substituted read must not take My work with it:
		// the group is work ADDED to the page, never work the page depends on.
		substituted = new Error('resolver unavailable')
		const wrapper = await mountIndex()
		expect(wrapper.find('.cn-index-page-stub').exists()).toBe(true)
		expect(wrapper.find('[data-testid="substituted-work"]').exists()).toBe(false)
	})
})

describe('the My work tile', () => {
	it('merges a substituted task in beside your own, marked', async () => {
		ownTasks = [
			{ id: 't-own', title: 'Your own task', daysUntilDue: 3, isTerminal: false },
		]
		substituted = {
			cases: [],
			tasks: [
				{
					id: 't-anna',
					title: 'Anna task',
					dueDate: '2026-07-20T00:00:00+00:00',
					_substituted: {
						absentee: 'anna',
						substitutionId: 'sub-1',
						until: '2026-07-21',
					},
				},
			],
		}

		const wrapper = await mountTile()
		const rows = wrapper.findAll('.cn-row')
		expect(rows).toHaveLength(2)
		// Own work first, unchanged and unmarked.
		expect(rows[0].find('.cn-row__title').text()).toBe('Your own task')
		expect(rows[0].find('.cn-row__marker').text()).toBe('')
		// The substituted one beside it, naming the colleague it belongs to.
		expect(rows[1].find('.cn-row__title').text()).toBe('Anna task')
		expect(rows[1].find('.cn-row__marker').text()).toContain('anna')
		// And a deadline it has, rather than "No deadline": the substituted row
		// carries `dueDate` and none of the engine inbox's own counters.
		expect(rows[1].find('.cn-row__days').text()).not.toContain('No deadline')
	})

	it('carries the marker column only while there is something to mark', async () => {
		ownTasks = [{ id: 't-own', title: 'Your own task', isTerminal: false }]
		const bare = await mountTile()
		expect(bare.find('.cn-columns').text()).not.toContain('substitutedMarker')

		substituted = {
			cases: [],
			tasks: [
				{
					id: 't-anna',
					title: 'Anna task',
					_substituted: { absentee: 'anna', until: '2026-07-21' },
				},
			],
		}
		const marked = await mountTile()
		expect(marked.find('.cn-columns').text()).toContain('substitutedMarker')
	})

	it('hides the substituted rows on the toggle and keeps your own', async () => {
		ownTasks = [{ id: 't-own', title: 'Your own task', isTerminal: false }]
		substituted = {
			cases: [],
			tasks: [
				{
					id: 't-anna',
					title: 'Anna task',
					_substituted: { absentee: 'anna', until: '2026-07-21' },
				},
			],
		}

		const wrapper = await mountTile()
		expect(wrapper.findAll('.cn-row')).toHaveLength(2)

		await wrapper
			.find('[data-testid="substituted-toggle-widget"]')
			.trigger('click')
		await flushPromises()

		const rows = wrapper.findAll('.cn-row')
		expect(rows).toHaveLength(1)
		expect(rows[0].find('.cn-row__title').text()).toBe('Your own task')
	})

	it('never lists the same task twice when it is both yours and routed', async () => {
		ownTasks = [{ id: 't-1', title: 'Shared task', isTerminal: false }]
		substituted = {
			cases: [],
			tasks: [
				{
					id: 't-1',
					title: 'Shared task',
					_substituted: { absentee: 'anna', until: '2026-07-21' },
				},
			],
		}

		const wrapper = await mountTile()
		expect(wrapper.findAll('.cn-row')).toHaveLength(1)
	})
})

describe('the helper module', () => {
	/**
	 * The check the last round of this feature needed and did not have.
	 *
	 * Every helper was correct, tested by nothing, and imported by nothing.
	 * This reads the export names out of the module source and demands each
	 * one appears in an import from a My work surface, so a helper that loses
	 * its call site again fails here instead of going quietly dark.
	 */
	it('has a caller on a My work surface for every export', () => {
		const source = readFileSync(
			resolve(ROOT, 'src/utils/substitutionHelpers.js'),
			'utf8',
		)
		const exports = [
			...source.matchAll(/^export function (\w+)/gm),
		].map((m) => m[1])
		expect(exports.length).toBeGreaterThan(0)

		const callers = [
			readFileSync(resolve(ROOT, 'src/views/MyWorkCards.vue'), 'utf8'),
			readFileSync(
				resolve(ROOT, 'src/views/widgets/MyWorkWidget.vue'),
				'utf8',
			),
		].join('\n')

		const orphans = exports.filter(
			(name) => new RegExp(`\\b${name}\\b`).test(callers) === false,
		)
		expect(
			orphans,
			'every export of substitutionHelpers.js must be called from My work',
		).toEqual([])
	})
})
