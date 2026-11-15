/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation golden for `grantRows` (src/services/caseAccessApi.js):
 * every row the access panel renders, granted and refused, from each read,
 * with and without the object's own permission set.
 *
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import { grantRows, objectPermissionRows } from '../../src/services/caseAccessApi.js'

const OBJECT_GRANTS = [
	{ principal: 'alice', actions: ['read', 'update'], source: 'group', expires: '2026-12-31' },
	{ userId: 'bob', actions: ['read'], inheritedFrom: 'case-parent' },
	{ groupId: 'team-a', actions: ['delete'], expires: 0 },
	{ actions: ['read'] },
	{ principal: 'nobody' },
	null,
]

const ROLE_GRANTS = {
	roles: [
		{ role: 'behandelaar', actions: ['read', 'update'] },
		{ actions: ['read'] },
		{ role: 'leeg' },
		null,
	],
}

const CALLER = {
	user: 'carol',
	provenance: {
		read: { source: 'role', granted: true, role: 'behandelaar' },
		update: { granted: false, principal: 'group:x', stagedDeny: { rule: 'r-1' } },
		delete: null,
	},
}

const OBJECT_PERMISSIONS = {
	grants: [{ principal: 'dave', action: 'read', source: 'share' }],
	denies: [{ principal: 'eve', action: 'update' }],
}

/**
 * The deny rules, enforcing or staged.
 *
 * @param {boolean} enforcing Whether the deny rules are enforced.
 * @return {object} The deny read.
 */
function denies(enforcing) {
	return {
		enforcing,
		rules: [
			{ principal: 'mallory', action: 'read', level: 'schema', subject: 'case' },
			{ principal: 'trent', action: 'update', level: 'register' },
			{ action: 'delete' },
			{},
		],
	}
}

describe('grantRows golden', () => {
	it('renders every rule of every read when the object answered no permission set', () => {
		expect(grantRows({
			objectGrants: OBJECT_GRANTS,
			roleGrants: ROLE_GRANTS,
			callerScope: CALLER,
			denyRules: denies(true),
		})).toEqual([
			{ holder: 'alice', right: 'read', source: 'group', inheritedFrom: '', detail: '2026-12-31' },
			{ holder: 'alice', right: 'update', source: 'group', inheritedFrom: '', detail: '2026-12-31' },
			{ holder: 'bob', right: 'read', source: 'inherited', inheritedFrom: 'case-parent', detail: '' },
			{ holder: 'team-a', right: 'delete', source: 'share', inheritedFrom: '', detail: '' },
			{ holder: '', right: 'read', source: 'share', inheritedFrom: '', detail: '' },
			{ holder: 'behandelaar', right: 'read', source: 'role', detail: '' },
			{ holder: 'behandelaar', right: 'update', source: 'role', detail: '' },
			{ holder: '', right: 'read', source: 'role', detail: '' },
			{ holder: 'mallory', right: 'read', source: 'deny', detail: 'schema: case' },
			{ holder: 'trent', right: 'update', source: 'deny', detail: 'register: ' },
			{ holder: '', right: 'delete', source: 'deny', detail: '' },
			{ holder: '', right: '', source: 'deny', detail: '' },
			{ holder: 'carol', right: 'read', source: 'role', granted: true, detail: 'behandelaar', stagedDeny: null },
			{ holder: 'carol', right: 'update', source: 'unknown', granted: false, detail: 'group:x', stagedDeny: { rule: 'r-1' } },
			{ holder: 'carol', right: 'delete', source: 'unknown', granted: undefined, detail: '', stagedDeny: null },
		])
	})

	it('marks deny rules as staged when they are not enforced', () => {
		const rows = grantRows({ objectGrants: null, roleGrants: null, callerScope: null, denyRules: denies(false) })
		expect(rows.map((row) => row.source)).toEqual(['staged-deny', 'staged-deny', 'staged-deny', 'staged-deny'])
	})

	it('uses only the object permission set and the caller verdict when the object answered', () => {
		expect(grantRows({
			objectPermissions: OBJECT_PERMISSIONS,
			objectGrants: OBJECT_GRANTS,
			roleGrants: ROLE_GRANTS,
			callerScope: CALLER,
			denyRules: denies(true),
		})).toEqual([
			...objectPermissionRows(OBJECT_PERMISSIONS),
			{ holder: 'carol', right: 'read', source: 'role', granted: true, detail: 'behandelaar', stagedDeny: null },
			{ holder: 'carol', right: 'update', source: 'unknown', granted: false, detail: 'group:x', stagedDeny: { rule: 'r-1' } },
			{ holder: 'carol', right: 'delete', source: 'unknown', granted: undefined, detail: '', stagedDeny: null },
		])
	})

	it('renders nothing from empty reads', () => {
		expect(grantRows({ objectGrants: undefined, roleGrants: {}, callerScope: { user: 'x' }, denyRules: {} })).toEqual([])
	})

	it('renders a caller without a name as an empty holder', () => {
		expect(grantRows({ callerScope: { provenance: { read: { source: 'share', granted: true } } } })).toEqual([
			{ holder: '', right: 'read', source: 'share', granted: true, detail: '', stagedDeny: null },
		])
	})
})
