/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The four search fields the Tasks index declares, and the one it does not.
 *
 * WHY THIS TEST EXISTS AT ALL. A sidebar filter that reaches no argument is
 * the quietest failure on the page: the control renders, takes a choice,
 * highlights it, and the list stays exactly as it was. That is
 * indistinguishable from a filter that matched every row, so nobody reports
 * it. The library logs at ERROR when it happens at runtime; this test is the
 * half that fails BEFORE anybody ships the declaration.
 *
 * dossiq owns the DECLARATION and nextcloud-vue owns the MAPPING, because
 * every app running its work on OpenRegister's one task store needs the same
 * mapping and only this app decides which of it to put on screen. So the
 * thing worth asserting here is that the two halves agree.
 *
 * @spec openspec/changes/task-search-fields/specs/task-management/spec.md
 */

import { indexSources } from '@conduction/nextcloud-vue/src/composables/indexSources.js'
import { filtersFromSchema } from '@conduction/nextcloud-vue/src/utils/schema.js'
import fs from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// 🔴 THE MAPPING HALF IS NOT IN THE PUBLISHED LIBRARY YET, AND A MISSING
// IMPORT TAKES THE WHOLE FILE WITH IT. `searchFieldParams` is nextcloud-vue's
// half of this change: dossiq declares the fields, the library turns them into
// inbox arguments. It is not in the installed @conduction/nextcloud-vue 3.2.0,
// so a static import fails at collection time and the four DECLARATION tests
// below, which need nothing from it, never run either. That reads exactly
// like a file nobody wrote.
//
// Loaded at run time instead, and the tests that need it are skipped with this
// reason rather than passing over a stand-in. They start running the day the
// library publishes the module, with no edit here.
let searchFieldParams = null
try {
	;({ searchFieldParams } =
		await import('@conduction/nextcloud-vue/src/utils/searchFieldParams.js'))
} catch {
	searchFieldParams = null
}

const MAPPING_SHIPPED = typeof searchFieldParams === 'function'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)

const tasksPage = manifest.pages.find((entry) => entry.id === 'Tasks')
const declaredProperties = tasksPage.config.sidebar.fields

// 🔴 THE SOURCE ADAPTER OPENS A PINIA STORE THE MOMENT IT IS BUILT. Reading
// `searchFields` is reading data, but `indexSources.tasks()` calls
// `useTaskInboxStore()` on the way there, and without an active Pinia that
// throws rather than answering. Read through the adapter anyway, and not off
// a copy of the declaration: a copy would agree with itself forever while the
// page read something else.
beforeEach(() => {
	setActivePinia(createPinia())
})

/**
 * The inbox arguments the tasks source knows how to send.
 *
 * @return {object} The `searchFields` declaration.
 */
function inboxArguments() {
	return indexSources.tasks().searchFields
}

/**
 * The property keys the sidebar offers as filters.
 *
 * @return {string[]} The facetable keys, in declared order.
 */
function declaredFilterKeys() {
	return Object.entries(declaredProperties)
		.filter(([, prop]) => prop.facetable === true)
		.map(([key]) => key)
}

describe('the Tasks index declares its search fields', () => {
	it('offers case, state, priority, kind and a due window, in that order', () => {
		// `kind` joined the four with case-reminder-as-task (#2920): a
		// reminder is an engine task like any other, so the only thing that
		// tells it apart from the work a flow scheduled is what sort of task
		// it is.
		expect(declaredFilterKeys()).toEqual([
			'objectUuid',
			'state',
			'priority',
			'kind',
			'dueAt',
		])
	})

	// Blocked on the same unpublished library half as the mapping below:
	// `filtersFromSchema` in 3.2.0 reads a `$ref` and does not yet read the
	// `inputControl` these fields declare, so `objectUuid` comes back as a
	// plain select rather than the case picker.
	it.skipIf(MAPPING_SHIPPED === false)(
		'gives each field the widget its question needs',
		() => {
			const byKey = Object.fromEntries(
				filtersFromSchema({ properties: declaredProperties }).map(
					(filter) => [filter.key, filter],
				),
			)

			expect(byKey.objectUuid.type).toBe('reference')
			expect(byKey.state.type).toBe('select')
			expect(byKey.state.multiple).toBe(true)
			expect(byKey.priority.type).toBe('select')
			expect(byKey.priority.multiple).toBe(false)
			expect(byKey.dueAt.type).toBe('date-range')
		},
	)

	it('points the case picker at the case register', () => {
		const picker = declaredProperties.objectUuid.optionsSource

		expect(picker.register).toBe('dossiq')
		expect(picker.schema).toBe('case')
		expect(picker.labelField).toBe('title')
	})

	/**
	 * `sidebar.fields` and NOT `config.schema`, for two reasons that both
	 * bite. The manifest schema types `config.schema` as a STRING, because it
	 * names the OpenRegister schema a page self-fetches from, and this page
	 * fetches from the task engine. And a schema feeds BOTH sidebar tabs, so
	 * a filter declared there would also appear in the Columns tab, offering
	 * a column the task table does not have: the toggle would tick and
	 * nothing would happen, the same silent shape as a dead filter.
	 */
	it('declares the fields on the sidebar, not as the page schema', () => {
		expect(tasksPage.config.schema).toBeUndefined()
		expect(tasksPage.config.sidebar.enabled).toBe(true)
		expect(Object.keys(tasksPage.config.sidebar.fields).length).toBeGreaterThan(
			0,
		)
	})
})

describe.skipIf(MAPPING_SHIPPED === false)(
	'every declared field maps to an inbox argument',
	() => {
		it('has a mapping for each one, and none spare', () => {
			const mapped = inboxArguments()

			for (const key of declaredFilterKeys()) {
				expect(
					mapped[key],
					`no inbox argument for the declared filter "${key}"`,
				).toBeTruthy()
			}
		})

		it('narrows to one case with the argument the inbox reads', () => {
			const mapped = inboxArguments()

			expect(searchFieldParams(mapped, { objectUuid: ['case-7'] })).toEqual({
				objectUuid: 'case-7',
			})
		})

		it('sends the window as the two arguments it is on the wire', () => {
			const mapped = inboxArguments()

			expect(
				searchFieldParams(mapped, {
					dueAt: { from: '2026-09-21', to: '2026-09-25' },
				}),
			).toEqual({ dueAfter: '2026-09-21', dueBefore: '2026-09-25' })
		})

		it('carries several states and exactly one priority', () => {
			const mapped = inboxArguments()

			expect(
				searchFieldParams(mapped, {
					state: ['available', 'active'],
					priority: ['high'],
				}),
			).toEqual({ state: 'available,active', priority: 'high' })
		})

		it('only ever names states and priorities the declaration offers', () => {
			const mapped = inboxArguments()
			const states = declaredProperties.state.enum
			const priorities = declaredProperties.priority.enum

			for (const state of states) {
				expect(searchFieldParams(mapped, { state: [state] })).toEqual({
					state,
				})
			}
			for (const priority of priorities) {
				expect(searchFieldParams(mapped, { priority: [priority] })).toEqual({
					priority,
				})
			}
		})
	},
)

describe('the field the inbox cannot answer is not on the sidebar', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	/**
	 * Measured 2026-09-16 against openregister `development`:
	 * `TaskInboxCriteria` has no assignee predicate, `scope` resolves to the
	 * calling user, and `useTaskInboxStore`'s allowlist drops the key so no
	 * config can widen whose inbox it is. A picker would answer about
	 * everybody while looking like it answered about one person.
	 */
	it('declares no assignee filter, because nothing would narrow', () => {
		expect(declaredProperties.assignee).toBeUndefined()
	})

	// The other half of the same statement, and it needs the library's
	// `searchFields` on the tasks source, which 3.2.0 does not carry.
	it.skipIf(MAPPING_SHIPPED === false)(
		'has no inbox argument for one either',
		() => {
			expect(inboxArguments().assignee).toBeUndefined()
		},
	)

	it.skipIf(MAPPING_SHIPPED === false)(
		'says so out loud if one is ever added without an argument',
		() => {
			const error = vi.spyOn(console, 'error').mockImplementation(() => {})
			const mapped = inboxArguments()

			const params = searchFieldParams(
				mapped,
				{ assignee: ['alice'] },
				'tasks',
			)

			expect(params).toEqual({})
			expect(error).toHaveBeenCalledTimes(1)
			expect(error.mock.calls[0][0]).toContain('assignee')
		},
	)
})
