// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * What the access tab says about a field somebody cannot see.
 *
 * The rows ("applies to you" only when OpenRegister's own answer says so) are
 * rendered by the library's `object-permissions` widget and pinned in its
 * specs. What is pinned here is that no code under `src/` decides any of it.
 * The design note says the form and the data panel render what the platform
 * returns, and a single role comparison on one of these fields would make that
 * sentence false while everything still looked right.
 *
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

/** The five fields the change's worked example is about. */
const WORKED_EXAMPLE = [
	'confidentiality',
	'competentAuthority',
	'statutoryTerm',
	'qualityScore',
	'qualityStatus',
]

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
