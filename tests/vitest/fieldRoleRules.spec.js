// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * What the access tab says about a field somebody cannot see.
 *
 * Two assertions here are about things that cannot be seen on a screen.
 *
 *  - a row is marked as applying to the reader ONLY when OpenRegister's own
 *    answer says so. The declaration names groups, the browser does not know
 *    the reader's groups, and a panel that guessed would be a second evaluator
 *    of an access question. An instance that answers nothing gets "no", never
 *    an invented "yes";
 *  - no code under `src/` decides any of this. The design note says the form
 *    and the data panel render what the platform returns, and a single role
 *    comparison on one of these fields would make that sentence false while
 *    everything still looked right.
 *
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { fieldRoleRows, roleRuleSentence } from '../../src/utils/fieldRoleRules.js'

/** The five fields the change's worked example is about. */
const WORKED_EXAMPLE = [
	'confidentiality',
	'competentAuthority',
	'statutoryTerm',
	'qualityScore',
	'qualityStatus',
]

/** One declared rule, hiding the quality score from the handlers. */
const HIDES_THE_SCORE = {
	field: 'qualityScore',
	rule: 'hidden',
	groups: ['behandelaars'],
	heldBy: ['dossiq-coordinators', 'dossiq-quality'],
	reason: 'The quality officer scores the handling.',
}

/**
 * Every `.js` and `.vue` file under `src/`.
 *
 * @param {string} dir Where to start.
 * @return {Array<string>} The paths.
 */
function sourceFiles(dir) {
	const found = []
	readdirSync(dir).forEach((entry) => {
		const full = path.join(dir, entry)
		if (statSync(full).isDirectory()) {
			found.push(...sourceFiles(full))
			return
		}
		if (full.endsWith('.js') || full.endsWith('.vue')) {
			found.push(full)
		}
	})

	return found
}

describe('the rules a role meets', () => {
	it('keeps a rule that names a field, with both group lists', () => {
		expect(fieldRoleRows([HIDES_THE_SCORE], null)).toEqual([
			{
				field: 'qualityScore',
				rule: 'hidden',
				groups: ['behandelaars'],
				heldBy: ['dossiq-coordinators', 'dossiq-quality'],
				reason: 'The quality officer scores the handling.',
				appliesToMe: false,
			},
		])
	})

	it('drops a row naming no field, so hesitating does not render a blank line', () => {
		expect(fieldRoleRows([{ field: '  ', rule: 'hidden' }], null)).toEqual([])
	})

	it('drops a rule whose kind the platform does not read', () => {
		expect(
			fieldRoleRows([{ field: 'qualityScore', rule: 'readonly' }], null),
		).toEqual([])
	})

	it('marks a row as applying only when OpenRegister said so', () => {
		const [applied] = fieldRoleRows([HIDES_THE_SCORE], {
			hidden: ['qualityScore'],
			readOnly: [],
		})

		expect(applied.appliesToMe).toBe(true)
	})

	it('does not mark a row from the other kind of rule', () => {
		const [notApplied] = fieldRoleRows([HIDES_THE_SCORE], {
			hidden: [],
			readOnly: ['qualityScore'],
		})

		expect(notApplied.appliesToMe).toBe(false)
	})

	it('says no on an instance that answered nothing, rather than guessing yes', () => {
		expect(fieldRoleRows([HIDES_THE_SCORE], null)[0].appliesToMe).toBe(false)
	})

	it('names both sides in the sentence beside the field', () => {
		const sentence = roleRuleSentence(fieldRoleRows([HIDES_THE_SCORE], null)[0])

		expect(sentence).toContain('behandelaars')
		expect(sentence).toContain('dossiq-coordinators')
	})
})

describe('no dossiq code decides any of it', () => {
	it('branches on no role for the fields the rules are about', () => {
		const findings = []
		const src = path.resolve(__dirname, '../../src')

		sourceFiles(src).forEach((file) => {
			const body = readFileSync(file, 'utf8')
			WORKED_EXAMPLE.forEach((field) => {
				// A comparison of a group name in the same statement as one of
				// these fields is the shape the design note forbids. The
				// declarations themselves carry the group names without a
				// comparison, so a match here is code making a decision.
				const pattern = new RegExp(
					`${field}[^\\n]*(isInGroup|inGroup|hasGroup|groups\\.includes|OC\\.getCurrentUser)`,
				)
				if (pattern.test(body)) {
					findings.push(
						`${path.relative(src, file)} branches on a role for ${field}`,
					)
				}
			})
		})

		expect(findings).toEqual([])
	})
})
