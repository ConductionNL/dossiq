/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every branch of the small lifecycle readers: the row id, the two requester
 * notices, the reason on a held-back transition and the board-move lookup.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 * @spec openspec/specs/case-dashboard-view/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	archiveNotice,
	extensionNotice,
	findTransitionToStatus,
	rowId,
	transitionBlockReason,
} from '../../src/utils/caseLifecycleHelpers.js'

describe('rowId branches', () => {
	it('prefers id, then @self.id, then uuid, and stringifies', () => {
		expect(rowId({ id: 7, '@self': { id: 'b' }, uuid: 'c' })).toBe('7')
		expect(rowId({ '@self': { id: 'b' }, uuid: 'c' })).toBe('b')
		expect(rowId({ '@self': null, uuid: 'c' })).toBe('c')
		expect(rowId({})).toBe('')
		expect(rowId('a')).toBe('')
		expect(rowId(undefined)).toBe('')
	})
})

describe('the requester notices, branch by branch', () => {
	const calls = []
	const t = (s) => {
		calls.push(s)
		return '[' + s + ']'
	}

	it('reads a notice that is not an object as no notice', () => {
		expect(extensionNotice({ notice: 'sent' }, t)).toBeNull()
		expect(archiveNotice({ notice: null }, t)).toBeNull()
		expect(archiveNotice(undefined, t)).toBeNull()
	})

	it('translates the not-told sentence before the reason, and trims the reason', () => {
		calls.length = 0
		expect(extensionNotice({ notice: { noticeStatus: 'failed', noticeReason: '  No address.  ' } }, t))
			.toEqual({ told: false, text: '[The term is extended. The requester was not told.] [No address.]' })
		expect(calls).toEqual(['The term is extended. The requester was not told.', 'No address.'])
	})

	it('leaves the reason off when it is blank or absent', () => {
		expect(extensionNotice({ notice: { noticeReason: '   ' } }, t))
			.toEqual({ told: false, text: '[The term is extended. The requester was not told.]' })
		expect(archiveNotice({ notice: {} }, t))
			.toEqual({ told: false, text: '[The case is archived. The requester was not told.]' })
	})

	it('falls back to the bare string when translate is not a function', () => {
		expect(archiveNotice({ notice: { noticeStatus: 'sent' } }, null))
			.toEqual({ told: true, text: 'The case is archived. The requester was told.' })
		expect(extensionNotice({ notice: { noticeStatus: 'sent' } }))
			.toEqual({ told: true, text: 'The term is extended. The requester was told.' })
	})
})

describe('transitionBlockReason branches', () => {
	it('reads a failedGuards that is not a list as no guards', () => {
		expect(transitionBlockReason({ guardsPassed: false, failedGuards: 'x' })).toBe('')
		expect(transitionBlockReason({ guardsPassed: false })).toBe('')
	})

	it('skips guards without a usable name and takes the first named one', () => {
		expect(transitionBlockReason({
			guardsPassed: false,
			failedGuards: [null, { failureMessage: '  ' }, { type: ' role ' }, { failureMessage: 'Later' }],
		})).toBe('role')
	})

	it('needs an explicit false', () => {
		expect(transitionBlockReason({ guardsPassed: 0, failedGuards: [{ type: 'x' }] })).toBe('')
		expect(transitionBlockReason(null)).toBe('')
	})
})

describe('findTransitionToStatus', () => {
	const a = { id: 'a', toStatus: 's1' }
	const b = { id: 'b', toStatus: 's2' }

	it('finds the first offered transition landing on the status', () => {
		expect(findTransitionToStatus([null, 'x', a, b, { id: 'c', toStatus: 's2' }], 's2')).toBe(b)
		expect(findTransitionToStatus([{ id: 'n', toStatus: 5 }], 5)).toEqual({ id: 'n', toStatus: 5 })
	})

	it('answers null without a match, a status or a list', () => {
		expect(findTransitionToStatus([a], 's9')).toBeNull()
		expect(findTransitionToStatus([{ id: 'x' }], '')).toBeNull()
		expect(findTransitionToStatus([a], null)).toBeNull()
		expect(findTransitionToStatus({ 0: a }, 's1')).toBeNull()
	})
})
