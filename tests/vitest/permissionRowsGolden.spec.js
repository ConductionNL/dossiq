/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation golden for the object permission rows, the as-of rows and
 * the grant constraints (src/services/caseAccessApi.js): granted and refused
 * rules, enforced and staged refusals, answered and unanswered history.
 *
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import { asOfRows, constraintsOf, objectPermissionRows } from '../../src/services/caseAccessApi.js'

const GRANT = {
	principal: 'alice',
	action: 'read',
	level: 'schema',
	role: 'behandelaar',
	inheritedFrom: 'case-parent',
	declared: false,
	conditional: true,
	rule: { until: '2026-12-31', scopedTo: { area: 'noord' } },
}

const BARE = { action: 'update' }

/**
 * The row a rule renders as, with the given source and constraints.
 *
 * @param {object} rule The rule.
 * @param {object} expected The source and the fields that differ from a bare row.
 * @return {object} The row.
 */
function row(rule, expected) {
	return {
		holder: rule.principal || '',
		right: rule.action || '',
		detail: rule.role || '',
		inheritedFrom: rule.inheritedFrom || '',
		level: rule.level || '',
		role: rule.role || '',
		declared: rule.declared !== false,
		conditional: rule.conditional === true,
		until: '',
		scopedTo: null,
		...expected,
	}
}

describe('objectPermissionRows golden', () => {
	it.each([null, undefined, 'set', 4])('renders nothing for %s', (set) => {
		expect(objectPermissionRows(set)).toEqual([])
	})

	it.each([
		['enforcing', 'deny'],
		['staged', 'staged-deny'],
		[undefined, 'staged-deny'],
	])('renders holders then refusals (%s)', (denyEnforcement, refusal) => {
		const rows = objectPermissionRows({
			denyEnforcement,
			holders: [{ rules: [GRANT, BARE] }, { rules: null }, null, { rules: [{ principal: 'bob', action: 'delete' }] }],
			denied: [{ principal: 'eve', action: 'read', level: 'register' }, null],
		})
		expect(rows).toEqual([
			row(GRANT, { source: 'schema', until: '2026-12-31', scopedTo: { area: 'noord' } }),
			row(BARE, { source: 'object' }),
			row({ principal: 'bob', action: 'delete' }, { source: 'object' }),
			row({ principal: 'eve', action: 'read', level: 'register' }, { source: refusal }),
			{ ...row({}, { source: refusal }) },
		])
		expect(Object.keys(rows[0])).toEqual([
			'holder', 'right', 'source', 'detail', 'inheritedFrom', 'level', 'role',
			'declared', 'conditional', 'until', 'scopedTo',
		])
	})
})

describe('asOfRows golden', () => {
	it.each([
		[null, ''],
		[{ at: '2026-01-01' }, '2026-01-01'],
		[{ at: '2026-01-01', asOf: 'x' }, '2026-01-01'],
		[{ asOf: null }, ''],
	])('answers unanswered for %j', (history, at) => {
		expect(asOfRows(history)).toEqual({ answered: false, at, rows: [], setBy: '', changedAfterwardsBy: null })
	})

	it('renders the set as it stood, never as refusals', () => {
		expect(asOfRows({
			at: 'asked',
			asOf: {
				at: '2026-02-01',
				holders: [{ rules: [GRANT] }, null],
				setBy: 'admin',
				changedAfterwardsBy: { user: 'later' },
			},
		})).toEqual({
			answered: true,
			at: '2026-02-01',
			rows: [row(GRANT, { source: 'schema', until: '2026-12-31', scopedTo: { area: 'noord' } })],
			setBy: 'admin',
			changedAfterwardsBy: { user: 'later' },
		})
	})

	it('falls back to the asked moment and empty fields', () => {
		expect(asOfRows({ at: 'asked', asOf: {} })).toEqual({ answered: true, at: 'asked', rows: [], setBy: '', changedAfterwardsBy: null })
		expect(asOfRows({ asOf: { holders: [] } })).toEqual({ answered: true, at: '', rows: [], setBy: '', changedAfterwardsBy: null })
	})
})

describe('constraintsOf golden', () => {
	const NONE = { until: '', scopedTo: null }

	it.each([null, undefined, 'rule', 3])('has none for %s', (rule) => {
		expect(constraintsOf(rule, 'alice')).toEqual(NONE)
	})

	it('reads a verb grant from the entry itself', () => {
		expect(constraintsOf({ until: '2026-12-31', scopedTo: { a: 1 } }, 'x')).toEqual({ until: '2026-12-31', scopedTo: { a: 1 } })
		expect(constraintsOf({ until: 20261231, scopedTo: '' }, 'x')).toEqual(NONE)
	})

	it.each([
		[[{ principal: 'alice', until: 'p' }], 'p'],
		[[{ group: 'alice', until: 'g' }], 'g'],
		[[{ role: 'alice', until: 'r' }], 'r'],
		[[{ user: 'alice', until: 'u' }], 'u'],
		[[{ principal: 'bob', until: 'b' }, { user: 'alice', until: 'second' }], 'second'],
	])('picks the entry naming the principal out of %j', (rule, until) => {
		expect(constraintsOf(rule, 'alice').until).toBe(until)
	})

	it('has none when the holder list names the principal only as a string or not at all', () => {
		expect(constraintsOf(['alice'], 'alice')).toEqual(NONE)
		expect(constraintsOf([null, { principal: 'bob' }], 'alice')).toEqual(NONE)
		expect(constraintsOf([[{ principal: 'alice' }]], 'alice')).toEqual(NONE)
	})
})
