/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every tenantUser the example set writes holds one of the three roles the
 * mandate matrix recognises, so the Waterland administrator is found by the
 * Administrator card (DqTenant "Beheerder") and by the onboarding check.
 *
 * @spec exclude example set data, no behaviour of its own
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const set = JSON.parse(
	fs.readFileSync(path.resolve(__dirname, '../../lib/Settings/example-sets/zuiddrecht.json'), 'utf8'),
)

/**
 * Every object entry in the set, wherever it is nested.
 *
 * @param {*} node A part of the set.
 * @param {Array<object>} out Collected entries.
 * @return {Array<object>} The entries with a schema and data.
 */
function entries(node, out = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => entries(child, out))
	} else if (node !== null && typeof node === 'object') {
		if (typeof node.schema === 'string' && node.data !== undefined) {
			out.push(node)
		}
		Object.values(node).forEach((child) => entries(child, out))
	}
	return out
}

const tenantUsers = entries(set).filter((entry) => entry.schema === 'tenantUser')

describe('example set tenant users', () => {
	it('writes at least one tenant user', () => {
		expect(tenantUsers.length).toBeGreaterThan(0)
	})

	it('gives each a role the mandate matrix recognises', () => {
		tenantUsers.forEach((entry) => {
			expect(['tenant_admin', 'case_handler', 'viewer']).toContain(entry.data.role)
		})
	})

	it('names Waterland\'s administrator as tenant_admin', () => {
		const admin = tenantUsers.find((entry) => entry.key === 'waterland-beheerder')
		expect(admin.data.role).toBe('tenant_admin')
	})
})
