/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The roles card on the admin settings: each provisioned group with what it may do (REQ-CRS-14).
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/case-access-control/spec.md#requirement-each-application-role-states-what-its-members-may-do-req-crs-14
 */
import { describe, expect, it } from 'vitest'
import { roleActLabels, roleActRows } from '../../src/views/settings/roleActs.js'

const t = (_app, text) => text

describe('the roles card', () => {
	it('words each act of a role, in the order the state gives', () => {
		expect(roleActRows(t, [
			{ group: 'auditors', acts: ['export-ai-audit-log'] },
			{ group: 'controllers', acts: ['read-process-analytics', 'read-reports'] },
		])).toEqual([
			{ group: 'auditors', acts: ['Read and export the AI audit log'] },
			{ group: 'controllers', acts: ['Read process analytics', 'Read the management reports'] },
		])
	})

	it('shows an act it has no words for by its key, and drops rows without a group', () => {
		expect(roleActRows(t, [{ group: 'x', acts: ['new-act'] }, { acts: ['a'] }, null, { group: 'y' }])).toEqual([
			{ group: 'x', acts: ['new-act'] },
			{ group: 'y', acts: [] },
		])
		expect(roleActRows(t, undefined)).toEqual([])
	})

	it('words every act once', () => {
		const labels = roleActLabels(t)
		expect(Object.keys(labels)).toHaveLength(20)
		expect(new Set(Object.values(labels)).size).toBe(20)
	})
})
