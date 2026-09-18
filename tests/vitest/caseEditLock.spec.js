/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The two strings the case page's edit lock is built from.
 *
 * 🔴 THE LOCK URL IS ASSEMBLED FROM `config.register` AND `config.schema`, AND
 * A WRONG ONE FAILS IN SILENCE. `useObjectLock` builds
 * `/apps/openregister/api/objects/<register>/<schema>/<id>/lock`, and a slug no
 * register answers to comes back 404. The acquire then throws something that is
 * not a `LockConflictError`, CnDetailPage leaves the form open on purpose for
 * exactly that case, and the edit proceeds with no lock at all. Nothing on
 * screen says so. That is not hypothetical: the library passed the object-CACHE
 * key `<register>-<schema>` into that URL until nextcloud-vue#1202, so the lock
 * was never taken on any manifest-driven detail page in the fleet.
 *
 * So this file asserts the pair, and asserts it against the schema slug
 * OpenRegister actually stores rather than against a constant written twice.
 *
 * 🔑 IT DELIBERATELY ASSERTS NO BEHAVIOUR OF THE LOCK ITSELF. Taking it,
 * handing it back, withdrawing Edit and naming the holder are CnDetailPage's,
 * and they are tested there (`CnDetailPageEditLock.spec.js`,
 * `useObjectLock.endpoints.spec.js`). A copy of those assertions here would be
 * a second answer to a question this app does not own, and it would go on
 * passing after the library's behaviour changed.
 *
 * @spec openspec/changes/edit-lock-on-the-case-page/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const MANIFEST_PATH = path.join(ROOT, 'src', 'manifest.json')

const manifest = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))

/** The case page, which is where a case is edited. */
const caseDetail = manifest.pages.find(page => page.id === 'CaseDetail')

describe('the case page can be locked for an edit', () => {
	it('is a detail page, which is what carries the lock', () => {
		expect(caseDetail, 'CaseDetail is declared').toBeTruthy()
		expect(caseDetail.type).toBe('detail')
	})

	it('🔴 names the register and the schema the lock url is built from', () => {
		// Both, and spelled as OpenRegister stores them. `dossiq-case` is the
		// object-cache key and is NOT a schema; putting it here would produce a
		// lock url nothing answers, and the edit would go ahead unlocked with
		// nothing on screen to say so.
		expect(caseDetail.config.register).toBe('dossiq')
		expect(caseDetail.config.schema).toBe('case')
	})

	it('offers exactly one way to edit the record, so one lock covers it', () => {
		// A second edit affordance would be a second write path, and the lock
		// only covers the one CnDetailPage owns. Any header action that opened
		// its own case form would write past it.
		const editActions = (caseDetail.config.headerActions ?? []).filter(
			action => action.type === 'open-form'
				&& (action.createOverride ?? true) === false,
		)

		expect(editActions).toEqual([])
	})
})
