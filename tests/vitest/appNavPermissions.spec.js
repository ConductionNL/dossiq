/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Who sees the admin-only menu entries.
 *
 * `CnAppNav` filters items declaring a `permission` against the `permissions`
 * prop, and treats an EMPTY array as "the app did not say", so every item
 * renders:
 *
 *     if (!item.permission) return true
 *     if (!this.permissions || this.permissions.length === 0) return true
 *     return this.permissions.includes(item.permission)
 *
 * That is a gate that fails OPEN, and it failed open for everyone who is not
 * an admin: the computed answered `[]` for them, because its only other source
 * was `OC.currentUser.permissions` and `OC.currentUser` is the uid STRING.
 * Nobody noticed, because an admin gets a non-empty array and filters
 * correctly, so the entries looked gated to whoever checked.
 *
 * The assertions below are therefore about EMPTINESS as much as membership: a
 * list that omits `admin` is only a gate if it is also non-empty.
 *
 * The list itself now lives in `src/utils/permissions.js`, because the ROUTER
 * needs the same answer the nav needs and used to get no answer at all — see
 * `routePermissions.spec.js`. This file still asserts that `App.vue` hands the
 * nav that list rather than computing a second one.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterEach, describe, expect, it } from 'vitest'
import { currentPermissions } from '../../src/utils/permissions.js'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const appSource = fs.readFileSync(path.join(ROOT, 'src', 'App.vue'), 'utf8')

afterEach(() => {
	delete globalThis.window
})

/**
 * The permissions the nav is given, evaluated against a stubbed admin flag.
 *
 * @param {boolean} isAdmin Whether the account is in the admin group.
 *
 * @return {Array<string>} The permissions the nav would be given.
 */
function permissionsFor(isAdmin) {
	globalThis.window = { OC: { isUserAdmin: () => isAdmin } }
	return currentPermissions()
}

/** Every menu entry that declares a permission. @return {Array} The entries. */
function gatedEntries() {
	return (manifest.menu || []).filter((entry) => entry.permission)
}

describe('the nav permission gate', () => {
	it('gives an ordinary account a list that is not empty', () => {
		const permissions = permissionsFor(false)
		expect(permissions.length).toBeGreaterThan(0)
	})

	it('withholds admin from an ordinary account', () => {
		expect(permissionsFor(false)).not.toContain('admin')
	})

	it('grants admin to the admin group', () => {
		expect(permissionsFor(true)).toContain('admin')
	})

	it('gates at least the Integrations entry, and gates nothing else by accident', () => {
		const gated = gatedEntries()
		expect(gated.map((entry) => entry.id)).toContain('IntegrationsMenu')
		for (const entry of gated) {
			expect(
				permissionsFor(false),
				`${entry.id} is declared ${entry.permission} and must not render for an ordinary account`,
			).not.toContain(entry.permission)
		}
	})
})

describe('what App.vue hands the nav', () => {
	it('is the shared list, not a second copy of the rule', () => {
		// A copy is how the nav and the router came to disagree in the first
		// place. If this stops matching, check that BOTH surfaces moved.
		expect(appSource).toMatch(/return currentPermissions\(\)/)
		expect(appSource).toMatch(
			/import \{ currentPermissions \} from '\.\/utils\/permissions\.js'/,
		)
	})
})
