// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The case access panel: who holds which right, and where the grant came from.
 *
 * Every assertion below guards a way this panel could be WRONG rather than
 * merely absent, because an access panel that is absent is noticed and one
 * that is confidently wrong is not.
 *
 *  - A failed read must answer `null`, never `[]`. An empty list is a
 *    legitimate answer (a case with no share grants has none), so a read that
 *    fell back to `[]` would tell an auditor "nobody holds this" when the
 *    truth was "we could not ask". The two are opposite answers rendered as
 *    the same empty table.
 *
 *  - A deny and a grant on the same holder and the same right must BOTH be
 *    listed. The moment this panel dropped the grant because a deny exists it
 *    would be a second evaluator of a question OpenRegister already answers
 *    (D-1), and the first disagreement between two evaluators of THIS question
 *    is a disclosure. So the assertion is a count, and it fails if anybody
 *    adds the filter that looks obviously right.
 *
 *  - The scopes read must pick the case row BY NAME. `scopes[0]` passes every
 *    test written against a one-row fixture and names whichever schema sorts
 *    first the day a second one matches.
 *
 *  - The tab must be reachable: a sidebar tab naming a `component` that is not
 *    a registry key renders an empty panel and logs nothing, and an icon that
 *    is not in src/icons.js renders no glyph at all.
 *
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	CASE_SCHEMA,
	fetchCallerScope,
	fetchObjectGrants,
	grantRows,
} from '../../src/services/caseAccessApi.js'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(
	path.join(ROOT, 'src', 'registry.js'),
	'utf8',
)
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')

/** Every sidebar tab declared on any page of the manifest. */
function sidebarTabs() {
	const tabs = []
	for (const page of manifest.pages || []) {
		for (const tab of page?.config?.sidebar?.tabs || page?.sidebar?.tabs || []) {
			tabs.push({ page: page.id, ...tab })
		}
	}
	return tabs
}

describe('The access panel reads OpenRegister and computes nothing', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('answers null when the grants cannot be read, never an empty list', async () => {
		axios.get.mockRejectedValueOnce(new Error('openregister is down'))

		expect(await fetchObjectGrants('case-1')).toBeNull()
	})

	it('answers null when the body is not JSON, because a string has no results', async () => {
		// A Nextcloud instance that answers the SPA shell returns 200 with an
		// HTML string. `body.results` on a string is undefined, which would
		// otherwise render as "this case has no grants".
		axios.get.mockResolvedValueOnce({ data: '<!DOCTYPE html><html></html>' })

		expect(await fetchObjectGrants('case-1')).toBeNull()
	})

	it('answers the grants OpenRegister listed', async () => {
		axios.get.mockResolvedValueOnce({
			data: { results: [{ principal: 'behandelaars', actions: ['read'] }] },
		})

		expect(await fetchObjectGrants('case-1')).toEqual([
			{ principal: 'behandelaars', actions: ['read'] },
		])
	})

	it('picks the case row out of the scopes matrix by name, not by position', async () => {
		axios.get.mockResolvedValueOnce({
			data: {
				user: 'alice',
				isAdmin: false,
				groups: ['behandelaars'],
				scopes: [
					{ schema: 'caseType', actions: ['read'], provenance: {} },
					{
						schema: CASE_SCHEMA,
						actions: ['read', 'update'],
						provenance: { read: { granted: true, source: 'role', role: 'behandelaar' } },
					},
				],
			},
		})

		const scope = await fetchCallerScope()

		expect(scope.schema).toBe(CASE_SCHEMA)
		expect(scope.user).toBe('alice')
	})

	it('answers null when no scope row names the case schema', async () => {
		axios.get.mockResolvedValueOnce({
			data: { user: 'alice', scopes: [{ schema: 'caseType', actions: ['read'] }] },
		})

		expect(await fetchCallerScope()).toBeNull()
	})

	it('lists every holder with the source of their grant', () => {
		const rows = grantRows({
			objectGrants: [
				{ principal: 'gemeente-noord', actions: ['read'], source: 'group' },
				{ principal: 'partner-zuid', actions: ['read'] },
			],
			roleGrants: { roles: [{ role: 'behandelaar', actions: ['read', 'update'] }] },
			callerScope: null,
			denyRules: null,
		})

		expect(rows).toContainEqual(
			expect.objectContaining({ holder: 'gemeente-noord', right: 'read', source: 'group' }),
		)
		expect(rows).toContainEqual(
			expect.objectContaining({ holder: 'partner-zuid', right: 'read', source: 'share' }),
		)
		expect(rows).toContainEqual(
			expect.objectContaining({ holder: 'behandelaar', right: 'update', source: 'role' }),
		)
	})

	it('lists a deny beside the grant it will remove, and subtracts nothing', () => {
		const rows = grantRows({
			objectGrants: null,
			roleGrants: { roles: [{ role: 'waarnemers', actions: ['read'] }] },
			callerScope: null,
			denyRules: {
				enforcing: false,
				rules: [{ level: 'schema', subject: 'case', action: 'read', principal: 'waarnemers' }],
			},
		})

		// Two rows on one holder and one right: the grant, and the rule that
		// takes it away. One row would mean this panel decided which of the two
		// wins, which is the second evaluator D-1 forbids.
		expect(rows.filter((row) => row.holder === 'waarnemers' && row.right === 'read')).toHaveLength(2)
		expect(rows.map((row) => row.source)).toContain('role')
		expect(rows.map((row) => row.source)).toContain('staged-deny')
	})

	it('says a refusal is in force when OpenRegister says it is enforcing', () => {
		const rows = grantRows({
			objectGrants: null,
			roleGrants: null,
			callerScope: null,
			denyRules: {
				enforcing: true,
				rules: [{ level: 'schema', subject: 'case', action: 'read', principal: 'waarnemers' }],
			},
		})

		expect(rows[0].source).toBe('deny')
	})

	it("carries the caller's own verdict with the source OpenRegister named", () => {
		const rows = grantRows({
			objectGrants: null,
			roleGrants: null,
			callerScope: {
				user: 'alice',
				provenance: {
					update: {
						granted: false,
						source: 'deny',
						deny: { principal: 'waarnemers', rule: 'waarnemers' },
					},
				},
			},
			denyRules: null,
		})

		expect(rows).toContainEqual(
			expect.objectContaining({
				holder: 'alice',
				right: 'update',
				source: 'deny',
				granted: false,
			}),
		)
	})
})

describe('The access tab is wired, not orphaned', () => {
	it('is declared as a sidebar tab on the case page', () => {
		const tab = sidebarTabs().find((entry) => entry.id === 'access')

		expect(tab, 'A case with no access tab answers nobody about who could open it.').toBeTruthy()
		expect(tab.component).toBe('CaseAccessTab')
	})

	it('names a component the registry actually answers', () => {
		// A `component:` sidebar tab naming a key the registry does not hold
		// renders an empty panel, and the warning goes to a console nobody reads.
		expect(registrySource).toContain('CaseAccessTab: {')
		expect(registrySource).toContain(
			"import CaseAccessTab from './views/cases/components/CaseAccessTab.vue'",
		)
	})

	it('names an icon that is registered, or the tab has no glyph', () => {
		const tab = sidebarTabs().find((entry) => entry.id === 'access')

		expect(iconsSource).toContain(tab.icon)
	})
})
