// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A deelzaak inherits its parent's grants, and the page says so (row Q13.23).
 *
 * The rule itself is resolved in OpenRegister and pinned on the PHP side in
 * `tests/Unit/Service/CaseAccessGuardInheritanceTest.php`. What is pinned here
 * is the half a reader sees, and each of these is a way it could be wrong
 * while looking fine:
 *
 *  - `inheritedFrom` falling into `source`. `sourceLabel()` returns its
 *    argument unchanged for a key it does not know, so a uuid arriving there
 *    renders as a raw identifier under a column headed Where it comes from.
 *    That is an id standing where prose was expected: it reads as a broken
 *    label, and nothing warns.
 *
 *  - the provenance going missing. The grant is not on the case in front of
 *    the reader, so a handler who cannot see which case granted it cannot
 *    remove it at all.
 *
 *  - the Sharing tab staying silent. A share that quietly widens to the
 *    deelzaken is the failure this row is about, inverted, and a warning after
 *    the share is no warning.
 *
 *  - the hierarchy declaration never reaching the instance. An unknown
 *    configuration key is dropped on import in silence, so the schema block
 *    and `SchemaSlugMap::SCHEMA_ANNOTATION_KEYS` are asserted together.
 *
 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { grantRows, objectPermissionRows } from '../../src/services/caseAccessApi.js'

const ROOT = path.resolve(__dirname, '../..')
const register = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'),
		'utf8',
	),
)
const slugMapSource = fs.readFileSync(
	path.join(ROOT, 'lib', 'Service', 'Settings', 'SchemaSlugMap.php'),
	'utf8',
)
const accessTabSource = fs.readFileSync(
	path.join(ROOT, 'src', 'views', 'cases', 'components', 'CaseAccessTab.vue'),
	'utf8',
)
const sharingTabSource = fs.readFileSync(
	path.join(ROOT, 'src', 'views', 'cases', 'components', 'CaseSharingTab.vue'),
	'utf8',
)
const guardSource = fs.readFileSync(
	path.join(ROOT, 'lib', 'Service', 'CaseAccessGuard.php'),
	'utf8',
)

const caseSchema = register.components.schemas.case

describe('the case declares which edge a grant travels down', () => {
	it('names parentCase as the hierarchy edge, in both spellings', () => {
		const hierarchy = caseSchema.configuration['x-openregister-hierarchy']
		expect(hierarchy).toBeDefined()
		// `parent` is openregister's canonical key (rbac-inherits-to-children,
		// openregister#3873) and wins where both are present. `parentField` is
		// the spelling dossiq shipped first and stays beside it, because an
		// instance still running a pre-#3873 openregister reads that one and
		// nothing else: dropping it would take the edge away from exactly the
		// instances that have no inheritance to fall back on.
		expect(hierarchy.parent).toBe('parentCase')
		expect(hierarchy.parentField).toBe('parentCase')
	})

	it('caps the depth, because nothing validates the chain on write', () => {
		const hierarchy = caseSchema.configuration['x-openregister-hierarchy']
		expect(typeof hierarchy.maxDepth).toBe('number')
		expect(hierarchy.maxDepth).toBeGreaterThan(0)
	})

	it('inherits read and nothing else', () => {
		// D-3, and the measured half of the competitor's behaviour: read on
		// the root reached the grandchild and was refused a write.
		const hierarchy = caseSchema.configuration['x-openregister-hierarchy']
		expect(hierarchy.inheritedVerbs).toEqual(['read'])
	})

	it('never declares relatedCases as an edge', () => {
		// A peer link is not a parent. Declaring it would carry a grant
		// sideways along every relation a handler ever made.
		const declared = JSON.stringify(
			caseSchema.configuration['x-openregister-hierarchy'],
		)
		expect(declared).not.toContain('relatedCases')
	})

	it('keeps the key OpenRegister would otherwise drop in silence', () => {
		// An unknown configuration key is dropped on import without a word, so
		// a block that is not in this list never reaches the instance and
		// OpenRegister reports no inheritance while dossiq believes it
		// declared some.
		expect(slugMapSource).toContain("'x-openregister-hierarchy'")
	})

	it('moves the case schema version, or the block is never imported', () => {
		const [major, minor] = caseSchema.version.split('.').map(Number)
		expect(major * 1000 + minor).toBeGreaterThanOrEqual(1026)
	})

	it('agrees with the depth the guard enforces', () => {
		// Two numbers describing one chain drift, and the drift is invisible:
		// the guard would refuse at a depth the schema says is fine.
		const hierarchy = caseSchema.configuration['x-openregister-hierarchy']
		const declared = guardSource.match(
			/HIERARCHY_MAX_DEPTH\s*=\s*(\d+)/,
		)
		expect(declared).not.toBeNull()
		expect(Number(declared[1])).toBe(hierarchy.maxDepth)
	})
})

describe('an inherited grant names the case it came from', () => {
	it('does not put the ancestor id in the source', () => {
		const rows = grantRows({
			objectGrants: [
				{
					principal: 'alice',
					actions: ['read'],
					inheritedFrom: '2f1c9a10-0000-4000-8000-000000000001',
				},
			],
			roleGrants: null,
			callerScope: null,
			denyRules: null,
		})

		expect(rows).toHaveLength(1)
		expect(rows[0].source).toBe('inherited')
		expect(rows[0].inheritedFrom).toBe('2f1c9a10-0000-4000-8000-000000000001')
	})

	it('keeps a source OpenRegister named, inherited or not', () => {
		const rows = grantRows({
			objectGrants: [
				{ principal: 'alice', actions: ['read'], source: 'group' },
				{ principal: 'bob', actions: ['read'] },
			],
			roleGrants: null,
			callerScope: null,
			denyRules: null,
		})

		expect(rows.map((row) => row.source)).toEqual(['group', 'share'])
		expect(rows.map((row) => row.inheritedFrom)).toEqual(['', ''])
	})

	it('carries the ancestor through the object permission set too', () => {
		// The set is the first read since openregister#3744; the five older
		// reads are the fallback. A provenance carried on one path and not the
		// other is a panel that names the granting case on some instances.
		const rows = objectPermissionRows({
			denyEnforcement: 'enforcing',
			holders: [
				{
					rules: [
						{
							principal: 'alice',
							action: 'read',
							level: 'inherited',
							inheritedFrom: 'parent-case-uuid',
						},
					],
				},
			],
			denied: [],
		})

		expect(rows).toHaveLength(1)
		expect(rows[0].inheritedFrom).toBe('parent-case-uuid')
	})

	it('gives the panel a word for the inherited source', () => {
		// Without the label, `sourceLabel()` returns the key unchanged and the
		// column reads `inherited` rather than a sentence.
		expect(accessTabSource).toContain('inherited: t(')
		expect(accessTabSource).toContain('inheritedHint(row)')
	})

	it('renders the ancestor inside a sentence, never bare', () => {
		// The id is an answer only when something says what it is.
		expect(accessTabSource).toMatch(
			/Granted on case \{case\}, which this one hangs under/,
		)
	})
})

describe('the Sharing tab warns before the share, not after', () => {
	it('states that a share reaches the sub-cases', () => {
		expect(sharingTabSource).toContain('sharing-reaches-deelzaken')
		expect(sharingTabSource).toMatch(/Sharing this case lets the holder read/)
	})

	it('counts the sub-cases through parentCase, not through relatedCases', () => {
		expect(sharingTabSource).toContain('parentCase: this.objectId')
		expect(sharingTabSource).not.toContain('relatedCases: this.objectId')
	})

	it('says nothing when the case has no sub-cases', () => {
		// A warning about sub-cases that do not exist teaches a handler to
		// ignore the line, which costs more than the line is worth.
		expect(sharingTabSource).toContain('v-if="deelzaken.length > 0"')
	})
})

describe('the write does not widen on the way down', () => {
	it('keeps the ancestor walk out of the mutation answer', () => {
		// The property is the ABSENCE of a call, so it is asserted as one: the
		// mutation method must not resolve a source.
		const mutation = guardSource.slice(
			guardSource.indexOf('public function hasCaseMutationAccess'),
			guardSource.indexOf('public function hasCaseReadAccess'),
		)
		expect(mutation).not.toContain('readAccessSource(')
		expect(mutation).not.toContain('parentCase')
	})
})
