// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The CMMN task lifecycle: which transitions exist, which statuses are
 * terminal, and the labels a task shows.
 *
 * @spec openspec/specs/task-management/spec.md
 */
import { describe, expect, it } from 'vitest'
import {
	getAllowedTransitions,
	getStatusLabel,
	getTransitionLabel,
	isTerminalStatus,
	TASK_STATUSES,
	validateTransition,
} from '../../src/utils/taskLifecycle.js'

describe('getAllowedTransitions', () => {
	it.each([
		['available', ['active', 'terminated', 'disabled']],
		['active', ['completed', 'terminated']],
		['completed', []],
		['terminated', []],
		['disabled', []],
		['unknown', []],
	])('allows %s to move to %j', (status, targets) => {
		expect(getAllowedTransitions(status)).toEqual(targets)
	})
})

describe('validateTransition', () => {
	it('accepts a transition the lifecycle allows', () => {
		expect(validateTransition('available', 'active')).toBe(true)
		expect(validateTransition('active', 'completed')).toBe(true)
	})

	it('refuses a transition the lifecycle does not allow, or from an unknown status', () => {
		expect(validateTransition('available', 'completed')).toBe(false)
		expect(validateTransition('completed', 'active')).toBe(false)
		expect(validateTransition('unknown', 'active')).toBe(false)
	})
})

describe('isTerminalStatus', () => {
	it('treats completed, terminated and disabled as terminal and nothing else', () => {
		expect(Object.values(TASK_STATUSES).filter(isTerminalStatus)).toEqual(['completed', 'terminated', 'disabled'])
		expect(isTerminalStatus('unknown')).toBe(false)
	})
})

describe('labels', () => {
	it.each([
		['available', 'Available'],
		['active', 'Active'],
		['completed', 'Completed'],
		['terminated', 'Terminated'],
		['disabled', 'Disabled'],
		['custom', 'custom'],
	])('labels status %s as "%s"', (status, label) => {
		expect(getStatusLabel(status)).toBe(label)
	})

	it.each([
		['active', 'Start'],
		['completed', 'Complete'],
		['terminated', 'Terminate'],
		['disabled', 'Disable'],
		['available', 'available'],
	])('labels the move to %s as "%s"', (status, label) => {
		expect(getTransitionLabel(status)).toBe(label)
	})
})
