// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The pure decisions behind the case task pane (task-on-the-case).
 *
 * Each of these is a decision the pane cannot get wrong quietly: a query
 * that filters client-side shows an empty pane on a case with work left, a
 * final-status list that misses `terminated` leaves a dead task in the pane
 * with no buttons, and an unresolved `@objectId` token sends View all to the
 * unfiltered Tasks list.
 *
 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
 */
import { describe, expect, it } from 'vitest'
import {
	FINAL_TASK_STATUSES,
	isFinalStatus,
	openTasksQuery,
	taskIdOf,
	taskRouteFor,
	viewAllRouteFor,
} from '../../src/utils/caseTaskPaneHelpers.js'

/** The widget content blob as `src/manifest.json` declares it. */
const CONTENT = {
	register: 'dossiq',
	schema: 'caseTask',
	filter: { case: '@objectId' },
	sort: { field: 'dueDate', dir: 'asc' },
	limit: 25,
	rowRoute: 'TaskDetail',
	viewAllRoute: 'Tasks',
	viewAllQuery: { case: '@objectId' },
}

describe('taskIdOf', () => {
	it('reads a bare id', () => {
		expect(taskIdOf({ id: 'task-1' })).toBe('task-1')
	})

	it('reads the @self id an OpenRegister row carries instead', () => {
		expect(taskIdOf({ '@self': { id: 'task-2' } })).toBe('task-2')
	})

	it('returns an empty string for a row it cannot read', () => {
		expect(taskIdOf(null)).toBe('')
		expect(taskIdOf('task-3')).toBe('')
		expect(taskIdOf({})).toBe('')
	})
})

describe('isFinalStatus', () => {
	it('holds every status the caseTask lifecycle declares final', () => {
		// The schema's `configuration.x-openregister-lifecycle.final`. A status
		// missing here leaves a finished task sitting in the pane.
		expect(FINAL_TASK_STATUSES).toEqual(['completed', 'terminated', 'disabled'])
		for (const status of FINAL_TASK_STATUSES) {
			expect(isFinalStatus(status)).toBe(true)
		}
	})

	it('does not treat activate as an ending', () => {
		expect(isFinalStatus('active')).toBe(false)
		expect(isFinalStatus('available')).toBe(false)
		expect(isFinalStatus('')).toBe(false)
		expect(isFinalStatus(undefined)).toBe(false)
	})
})

describe('openTasksQuery', () => {
	it('filters on the case and on the SERVER-side open flag', () => {
		expect(openTasksQuery('case-9', CONTENT)).toEqual({
			case: 'case-9',
			isTerminalStatus: false,
			_order: { dueDate: 'asc' },
			_limit: 25,
		})
	})

	it('falls back to a limit when the manifest names none', () => {
		expect(openTasksQuery('case-9', {})._limit).toBe(25)
		expect(openTasksQuery('case-9', { limit: 0 })._limit).toBe(25)
		expect(openTasksQuery('case-9', { limit: 5 })._limit).toBe(5)
	})
})

describe('taskRouteFor', () => {
	it('routes a row to the detail page the manifest names', () => {
		expect(taskRouteFor({ id: 'task-1' }, CONTENT)).toEqual({
			name: 'TaskDetail',
			params: { id: 'task-1' },
		})
	})

	it('returns null without a route or without an id', () => {
		expect(taskRouteFor({ id: 'task-1' }, {})).toBeNull()
		expect(taskRouteFor({}, CONTENT)).toBeNull()
	})
})

describe('viewAllRouteFor', () => {
	it('resolves @objectId so View all stays scoped to this case', () => {
		expect(viewAllRouteFor('case-9', CONTENT)).toEqual({
			name: 'Tasks',
			query: { case: 'case-9' },
		})
	})

	it('leaves a literal query value alone', () => {
		expect(
			viewAllRouteFor('case-9', {
				viewAllRoute: 'Tasks',
				viewAllQuery: { status: 'active' },
			}),
		).toEqual({ name: 'Tasks', query: { status: 'active' } })
	})

	it('returns null when no route is configured', () => {
		expect(viewAllRouteFor('case-9', {})).toBeNull()
	})
})
