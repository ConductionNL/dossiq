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
 *  - the provenance going missing. The grant is not on the case in front of
 *    the reader, so a handler who cannot see which case granted it cannot
 *    remove it at all. The rows themselves are rendered by the library's
 *    `object-permissions` widget and pinned in its specs.
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
const sharingTabSource = fs.readFileSync(
	path.join(ROOT, 'src', 'views', 'cases', 'components', 'CaseSharingTab.vue'),
	'utf8',
)
const guardSource = fs.readFileSync(
	path.join(ROOT, 'lib', 'Service', 'CaseAccessGuard.php'),
	'utf8',
)

const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)

const caseSchema = register.components.schemas.case

describe('the case declares which edge a grant travels down', () => {
	it('names parentCase as the hierarchy edge, in both spellings', () => {
		const hierarchy = caseSchema.configuration['x-openregister-hierarchy']
		expect(hierarchy).toBeDefined()
		// `parent` is openregister's canonical key (openregister#3873) and
		// wins where both are present. `parentField` is the spelling dossiq
		// shipped first and STAYS beside it: an instance still running a
		// pre-#3873 openregister reads only that one, and dropping it would
		// take the edge away from exactly the instances that have no
		// inheritance to fall back on — and take it away silently, because an
		// unknown configuration key is discarded without a word.
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

	it('enforces no depth of its own, so there is no second number to drift', () => {
		// This used to assert that the guard's own `HIERARCHY_MAX_DEPTH`
		// matched the schema's `maxDepth`, because two numbers describing one
		// chain drift and the drift is invisible. The right fix for that was
		// not to keep them in step: it was to stop having two. dossiq deleted
		// its walk when openregister#3873 shipped, so the only reader of
		// `maxDepth` is now the platform that owns the edge.
		//
		// Asserted as an ABSENCE, and the schema is read beside it so this
		// cannot pass by the declaration having quietly gone away too.
		const hierarchy = caseSchema.configuration['x-openregister-hierarchy']
		expect(
			hierarchy.maxDepth,
			'the schema still declares the cap',
		).toBeGreaterThan(0)
		expect(guardSource).not.toContain('HIERARCHY_MAX_DEPTH')
		expect(
			guardSource,
			'and no walk was left behind under another name',
		).not.toContain('readAccessSource')
	})
})

describe('an inherited grant names the case it came from', () => {
	it('is rendered by the library tab, which names the ancestor in a sentence', () => {
		// The rows and the sentence are pinned in @conduction/nextcloud-vue
		// (tests/utils/objectPermissions.spec.js, CnObjectPermissionsTab spec):
		// the ancestor id rides beside the source and never in it.
		const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
		const access = caseDetail.config.sidebar.tabs.find((tab) => tab.id === 'access')
		expect(access.widgets[0].type).toBe('object-permissions')
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
	it('keeps the platform grant out of the mutation answer', () => {
		// The property is the ABSENCE of a call, so it is asserted as one.
		//
		// 🔴 IT NAMES WHAT IS THERE NOW, not what used to be. This assertion
		// read `not.toContain('readAccessSource(')` until dossiq deleted its
		// own walk in favour of openregister#3873, at which point it passed
		// because the method no longer exists anywhere — a test that cannot
		// fail, guarding a rule that can still be broken. Inheritance now
		// arrives through `holdsPlatformGrant()`, so that is the call the
		// mutation path must not make.
		const mutation = guardSource.slice(
			guardSource.indexOf('public function hasCaseMutationAccess'),
			guardSource.indexOf('public function hasCaseReadAccess'),
		)
		expect(
			guardSource,
			'the call this asserts the absence of must exist',
		).toContain('holdsPlatformGrant(')
		expect(mutation).not.toContain('holdsPlatformGrant(')
		expect(mutation).not.toContain('parentCase')
	})
})
