/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lifecycle menu lists its acts in the order of board DqVolgendeStap, each with its icon.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import { buildActsMenu, iconFor } from '../../src/utils/caseActsMenu.js'

const ACTS = {
	acts: [
		{ act: 'finish', allowed: true },
		{ act: 'abort', allowed: true },
		{ act: 'archive', allowed: false, reason: 'Not ended.' },
	],
	alwaysAvailable: [{ id: 'note', label: 'Note', icon: 'NoteOutline' }],
}

const STATE = { canSuspend: true, canResume: false, canExtend: true, canReopen: false }

const TRANSITIONS = [{ id: 't-decide', label: 'Naar Besluit', guardsPassed: true }]

describe('the lifecycle menu order', () => {
	it('puts the moves first, then the always acts, then the board order', () => {
		const ids = buildActsMenu({ transitions: TRANSITIONS, state: STATE, acts: ACTS }).map((entry) => entry.id)

		expect(ids).toEqual(['t-decide', 'note', 'finish', 'suspend', 'extend', 'hold', 'resume', 'abort', 'archive', 'reopen'])
	})

	it('puts Restore where Reopen stands on an archived case, as the one last entry', () => {
		const ids = buildActsMenu({ state: STATE, acts: { ...ACTS, archived: true } }).map((entry) => entry.id)

		expect(ids.indexOf('unarchive')).toBeGreaterThan(ids.indexOf('abort'))
		expect(ids).not.toContain('reopen')
		expect(ids[ids.length - 1]).toBe('unarchive')
	})
})

describe('the lifecycle menu icons', () => {
	it('gives every act its icon, a move an arrow and an always act its declared icon', () => {
		const menu = buildActsMenu({ transitions: TRANSITIONS, state: STATE, acts: ACTS })
		const icons = Object.fromEntries(menu.map((entry) => [entry.id, iconFor(entry)]))

		expect(icons).toEqual({
			't-decide': 'ArrowRight',
			note: 'NoteOutline',
			finish: 'FlagOutline',
			suspend: 'PauseCircleOutline',
			extend: 'CalendarClock',
			hold: 'TimerSandEmpty',
			resume: 'Play',
			abort: 'StopCircleOutline',
			archive: 'ArchiveOutline',
			reopen: 'History',
		})
	})

	it('answers no icon for an entry it does not know', () => {
		expect(iconFor({ kind: 'gesture', id: 'unknown' })).toBe('')
		expect(iconFor(null)).toBe('')
	})
})
