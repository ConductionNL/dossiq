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
	asOfRows,
	CASE_SCHEMA,
	constraintsOf,
	fetchAccessHistory,
	fetchCallerScope,
	fetchObjectGrants,
	fetchObjectPermissions,
	grantRows,
	objectPermissionRows,
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

describe("The object's own permission set, and the rule behind each row", () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('keeps the status beside the set, so a 403 is not a failed read', async () => {
		// OpenRegister refuses this read to a caller who may open the case and
		// holds no manage right on it. Reported as `null` alone it would render
		// as "openregister could not be asked", which is wrong twice: it
		// answered, and the reader is not missing data, they are not entitled
		// to it.
		const refusal = new Error('forbidden')
		refusal.response = { status: 403 }
		axios.get.mockRejectedValueOnce(refusal)

		expect(await fetchObjectPermissions('case-1')).toEqual({ status: 403, set: null })
	})

	it('reports 0 when nothing answered at all, which is not a refusal', async () => {
		axios.get.mockRejectedValueOnce(new Error('openregister is down'))

		expect(await fetchObjectPermissions('case-1')).toEqual({ status: 0, set: null })
	})

	it('answers null for a body with no holders list, never an empty set', async () => {
		axios.get.mockResolvedValueOnce({ status: 200, data: { object: 'case-1' } })

		expect((await fetchObjectPermissions('case-1')).set).toBeNull()
	})

	it('names the level and the role on every row', () => {
		const rows = objectPermissionRows({
			denyEnforcement: 'staging',
			holders: [
				{
					principal: 'behandelaars',
					verbs: ['read'],
					rules: [
						{
							principal: 'behandelaars',
							action: 'read',
							level: 'schema',
							role: 'behandelaar',
							rule: [{ group: 'behandelaars' }],
							declared: true,
						},
					],
				},
			],
			denied: [],
		})

		expect(rows).toEqual([
			expect.objectContaining({
				holder: 'behandelaars',
				right: 'read',
				source: 'schema',
				role: 'behandelaar',
				level: 'schema',
				declared: true,
			}),
		])
	})

	it('marks a verb the catalogue does not publish as undeclared', () => {
		const rows = objectPermissionRows({
			holders: [
				{
					principal: 'archivaris',
					verbs: ['obliterate'],
					rules: [{ principal: 'archivaris', action: 'obliterate', level: 'object', declared: false }],
				},
			],
		})

		expect(rows[0].declared).toBe(false)
	})

	it('lists a refusal beside the grant and subtracts nothing', () => {
		const rows = objectPermissionRows({
			denyEnforcement: 'enforcing',
			holders: [
				{
					principal: 'waarnemers',
					verbs: ['read'],
					rules: [{ principal: 'waarnemers', action: 'read', level: 'register', declared: true }],
				},
			],
			denied: [{ principal: 'waarnemers', action: 'read', level: 'object', declared: true }],
		})

		// Two rows on one holder and one right, exactly as the five-read path
		// keeps them. One row would mean this function picked a winner, which
		// is the second evaluator D-1 forbids.
		expect(rows.filter((row) => row.holder === 'waarnemers' && row.right === 'read')).toHaveLength(2)
		expect(rows.map((row) => row.source)).toContain('deny')
	})

	it('calls a refusal staged while the instance has not switched it on', () => {
		const rows = objectPermissionRows({
			denyEnforcement: 'staging',
			holders: [],
			denied: [{ principal: 'waarnemers', action: 'read', level: 'object' }],
		})

		expect(rows[0].source).toBe('staged-deny')
	})

	it('does not replay the share and role reads when the object answered', () => {
		// The same grant would arrive twice, once with its rule and once
		// without, and an auditor counting holders would count it twice.
		const rows = grantRows({
			objectPermissions: {
				holders: [
					{
						principal: 'behandelaars',
						verbs: ['read'],
						rules: [{ principal: 'behandelaars', action: 'read', level: 'schema' }],
					},
				],
			},
			objectGrants: [{ principal: 'behandelaars', actions: ['read'] }],
			roleGrants: { roles: [{ role: 'behandelaar', actions: ['read'] }] },
			callerScope: null,
			denyRules: { enforcing: true, rules: [{ principal: 'behandelaars', action: 'read' }] },
		})

		expect(rows).toHaveLength(1)
		expect(rows[0].source).toBe('schema')
	})

	it("still carries the caller's own verdict, which the object's answer has not got", () => {
		const rows = grantRows({
			objectPermissions: { holders: [] },
			objectGrants: null,
			roleGrants: null,
			callerScope: {
				user: 'alice',
				provenance: { read: { granted: true, source: 'role', role: 'behandelaar' } },
			},
			denyRules: null,
		})

		expect(rows).toContainEqual(expect.objectContaining({ holder: 'alice', right: 'read' }))
	})
})

describe('An end and an area are rendered, never evaluated', () => {
	it('reads the end off a verb grant, which carries the entry itself', () => {
		expect(constraintsOf({ group: 'waarnemers', until: '2026-10-01T17:00:00+02:00' }, 'waarnemers'))
			.toEqual({ until: '2026-10-01T17:00:00+02:00', scopedTo: null })
	})

	it('picks the holder out of a role grant, which carries the whole list', () => {
		// A role grant reports its rule as every holder of the role, so the
		// entry naming THIS principal is the one with this row's end on it.
		// Reading the first entry would give one holder everybody else's date.
		const rule = [
			{ group: 'behandelaars' },
			{ group: 'waarnemers', until: '2026-10-01T17:00:00+02:00' },
		]

		expect(constraintsOf(rule, 'waarnemers').until).toBe('2026-10-01T17:00:00+02:00')
		expect(constraintsOf(rule, 'behandelaars').until).toBe('')
	})

	it('reads the area a grant is confined to', () => {
		expect(constraintsOf({ group: 'beheerders', scopedTo: { registers: ['zaken'] } }, 'beheerders'))
			.toEqual({ until: '', scopedTo: { registers: ['zaken'] } })
	})

	it('renders an end that has already passed, and drops no row for it', () => {
		// 🔴 THE POINT OF THE WHOLE FUNCTION. Whether an expired grant still
		// answers is resolved in OpenRegister, on every path a question takes.
		// A clock here would be a second one, and two clocks disagree first on
		// the day the grant runs out, which is the day somebody looks.
		const rows = objectPermissionRows({
			holders: [
				{
					principal: 'waarnemers',
					verbs: ['read'],
					rules: [
						{
							principal: 'waarnemers',
							action: 'read',
							level: 'object',
							rule: { group: 'waarnemers', until: '1999-01-01T00:00:00+00:00' },
						},
					],
				},
			],
		})

		expect(rows).toHaveLength(1)
		expect(rows[0].until).toBe('1999-01-01T00:00:00+00:00')
	})

	it('carries a bare string entry without inventing a constraint for it', () => {
		expect(constraintsOf('behandelaars', 'behandelaars')).toEqual({ until: '', scopedTo: null })
	})
})

describe('The panel answers who held a right on a past date', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('answers null for a body with no change list', async () => {
		axios.get.mockResolvedValueOnce({ status: 200, data: { object: 'case-1' } })

		expect((await fetchAccessHistory('case-1', '2026-03-01')).history).toBeNull()
	})

	it('reports the holders of that date, with who set them and what changed them after', () => {
		const answer = asOfRows({
			at: '2026-03-01T23:59:59',
			changes: [{ at: '2026-06-01T10:00:00+02:00', by: 'bob' }],
			asOf: {
				at: '2026-01-05T09:00:00+01:00',
				setBy: 'alice',
				changedAfterwardsBy: { at: '2026-06-01T10:00:00+02:00', by: 'bob' },
				holders: [
					{
						principal: 'behandelaars',
						verbs: ['read'],
						rules: [{ principal: 'behandelaars', action: 'read', level: 'object' }],
					},
				],
			},
		})

		expect(answer.answered).toBe(true)
		expect(answer.setBy).toBe('alice')
		expect(answer.changedAfterwardsBy.by).toBe('bob')
		expect(answer.rows).toHaveLength(1)
	})

	it('says a date is unanswered rather than reporting that nobody held anything', () => {
		// OpenRegister answers `asOf: null` when its trail does not reach back
		// that far. An empty table would claim nobody held a right that day,
		// which is a different statement and one this panel cannot make.
		const answer = asOfRows({ at: '2019-03-01', changes: [], asOf: null })

		expect(answer.answered).toBe(false)
		expect(answer.rows).toEqual([])
	})

	it('says a failed history read is unanswered too', () => {
		expect(asOfRows(null).answered).toBe(false)
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
