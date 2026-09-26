// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * A task's own number, due date and lock (REQ-TASK-046).
 *
 * 🔴 THE TEST THAT MATTERS IS THE ONE THAT PROVES NOTHING IS INVENTED. The
 * task engine has no number of its own and no lock, and the tempting thing to
 * do with a missing number is to derive one from the case number and a
 * position. That number would look real, be quoted in an email, and address
 * nothing: the engine has never heard of it. So the assertions below are as
 * much about what these helpers DO NOT produce as about what they do.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
import { describe, expect, it } from 'vitest'
import {
	candidatesOf,
	isUnclaimed,
	missingRequiredField,
	requiredFieldsOf,
	taskFormOf,
	taskLockOf,
	taskNumberOf,
	taskReferenceOf,
} from '../../src/utils/caseTaskPaneHelpers.js'

describe('a task shows its own number', () => {
	it('shows the number when the engine carries one', () => {
		const reference = taskReferenceOf({
			uuid: 'abc-123',
			number: 'TAAK-2026-0041',
		})

		expect(reference).toEqual({ value: 'TAAK-2026-0041', isNumber: true })
	})

	it('shows the engine identifier when it carries none, and says it is not a number', () => {
		const reference = taskReferenceOf({ uuid: 'abc-123' })

		expect(reference.value).toBe('abc-123')
		// The flag is the whole point: it is what lets the surface say "no
		// number yet" instead of presenting a uuid as if it were one.
		expect(reference.isNumber).toBe(false)
	})

	it('invents nothing at all for a task with no number', () => {
		expect(taskNumberOf({ uuid: 'abc-123' })).toBe('')
		expect(taskNumberOf({ uuid: 'abc-123', case: 'ZAAK-2026-0001' })).toBe('')
		expect(taskNumberOf(null)).toBe('')
	})
})

describe('a task shows its own lock', () => {
	it('tells "not locked" apart from "no lock is tracked"', () => {
		// Three answers, not two. The engine tracks no lock on a task today,
		// and rendering that as "not locked" would tell a handler nobody else
		// is editing it, which nothing here knows.
		expect(taskLockOf({ uuid: 'a' })).toBeNull()
		expect(taskLockOf({ uuid: 'a', locked: false })).toBe(false)
		expect(taskLockOf({ uuid: 'a', locked: true })).toBe(true)
	})

	it('reads a lock held by somebody as locked', () => {
		expect(taskLockOf({ uuid: 'a', lockedBy: 'hbakker' })).toBe(true)
		expect(taskLockOf({ uuid: 'a', lockedBy: '' })).toBeNull()
	})
})

describe('a task that is waiting to be taken', () => {
	it('is unclaimed when it has candidates and no assignee', () => {
		expect(
			isUnclaimed({ assignee: '', candidateGroups: ['Juridische Zaken'] }),
		).toBe(true)
		expect(
			isUnclaimed({
				assignee: 'hbakker',
				candidateGroups: ['Juridische Zaken'],
			}),
		).toBe(false)
	})

	it('is not unclaimed when nobody was asked', () => {
		// An unassigned task with no candidates is not waiting for a team to
		// pick it up: nobody was offered it. Offering a claim there would
		// invent a pool.
		expect(isUnclaimed({ assignee: '' })).toBe(false)
		expect(
			isUnclaimed({ assignee: '', candidateGroups: [], candidateUsers: [] }),
		).toBe(false)
	})

	it('names groups and people together', () => {
		expect(
			candidatesOf({
				candidateGroups: ['Juridische Zaken'],
				candidateUsers: ['hbakker', ''],
			}),
		).toEqual(['Juridische Zaken', 'hbakker'])
	})
})

describe('the form a task carries', () => {
	const FORM = {
		kind: 'fields',
		state: 'ready',
		fields: [
			{ field: 'aanwezigen', required: false, renderable: true },
			{ field: 'verslag', required: true, renderable: true },
		],
	}

	it('is the engine answer, or nothing', () => {
		expect(taskFormOf({ form: FORM })).toBe(FORM)
		expect(taskFormOf({ uuid: 'a' })).toBeNull()
		expect(taskFormOf({ form: { state: 'ready' } })).toBeNull()
	})

	it('names the required fields in declared order', () => {
		expect(requiredFieldsOf({ form: FORM })).toEqual(['verslag'])
	})

	it('names the first required field left empty', () => {
		expect(missingRequiredField({ form: FORM }, { aanwezigen: 'drie' })).toBe(
			'verslag',
		)
		expect(missingRequiredField({ form: FORM }, { verslag: '  ' })).toBe(
			'verslag',
		)
		expect(
			missingRequiredField({ form: FORM }, { verslag: 'Gehoord op 3 maart' }),
		).toBe('')
	})

	it('treats zero and false as answers', () => {
		const form = {
			kind: 'fields',
			fields: [{ field: 'bedrag', required: true }],
		}

		// `!answer` would refuse a required amount answered with zero, and the
		// handler would be told to fill in a field they had filled in.
		expect(missingRequiredField({ form }, { bedrag: 0 })).toBe('')
		expect(missingRequiredField({ form }, { bedrag: false })).toBe('')
	})
})
