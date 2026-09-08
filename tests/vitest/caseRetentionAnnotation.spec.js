/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case schema's retention annotation, and the decision it records.
 *
 * WHAT THIS IS GUARDING, because none of it fails loudly on its own.
 * `x-openregister-archival` is not documentation. OpenRegister validates it on
 * schema save, computes an expiry from it, and runs an HOURLY job
 * (`ArchivalRetentionTask`) that HARD-DELETES rows whose expiry has passed. So
 * every line of it is an instruction to destroy records, and a line that reads
 * like a comment is still an instruction.
 *
 * Two facts decide what may live here, and both are OpenRegister's, not ours:
 *
 * 1. The annotation counts from the row's `_created` timestamp and offers no
 *    way to override it. The Archiefwet counts a zaak's bewaartermijn from
 *    afhandeling, and ZGW spells that out as `brondatumArchiefprocedure`. A
 *    duration counted from creation is a different number for the same case,
 *    and it is the wrong one.
 * 2. The sweep does not check legal holds. Everything else in OpenRegister's
 *    destruction path does, and dossiq's whole bezwaar/beroep story depends on
 *    it, but the annotation sweep passes `_retentionSweep: true`, which is the
 *    one flag that skips the immutability gate.
 *
 * So the authoritative date for a zaak is `case.archiveActionDate`, derived
 * from the RESULT TYPE by zrc-021 (`Service\Archival\ArchivalNominationDeriver`),
 * and this annotation must not carry a second, contradicting answer. The
 * `default` stays because removing the annotation entirely would also remove
 * the five delete gates it earns; changing that number is a records decision
 * for a records owner, not a refactor.
 *
 * Three rules used to live here and NONE of them could ever fire, which is how
 * the whole question surfaced. They are asserted absent below.
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read one JSON file from the repo root.
 *
 * @param {...string} parts Path segments below the repo root.
 * @return {object} The parsed file.
 */
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const REGISTERS = [
	['lib', 'Settings', 'dossiq_register.json'],
	['lib', 'Settings', 'dossiq_mock_register.json'],
]

/** Every caseType slug the app actually seeds, across every seed file. */
function seededCaseTypeSlugs() {
	const slugs = new Set()
	const files = [
		path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'),
		...fs
			.readdirSync(path.join(ROOT, 'lib', 'Settings', 'register.d'))
			.filter((f) => f.endsWith('.json'))
			.map((f) => path.join(ROOT, 'lib', 'Settings', 'register.d', f)),
	]
	for (const file of files) {
		let parsed
		try {
			parsed = JSON.parse(fs.readFileSync(file, 'utf8'))
		} catch {
			continue
		}
		for (const object of parsed?.components?.objects ?? []) {
			if (object?.['@self']?.schema === 'caseType') {
				slugs.add(object['@self'].slug)
			}
		}
	}
	return slugs
}

describe('the case schema retention annotation', () => {
	for (const parts of REGISTERS) {
		const label = parts[parts.length - 1]
		const register = readJson(...parts)
		const archival = register.components.schemas.case['x-openregister-archival']

		it(`${label}: declares a retention default and nothing else`, () => {
			expect(archival).toBeDefined()
			expect(archival.retention.default).toBe('P10Y')
			// OpenRegister's validator rejects unknown keys under `retention`
			// with a 422, so the shape is not a matter of taste.
			expect(Object.keys(archival.retention)).toEqual(['default'])
		})

		// The rule that closes the question. A retention rule here is an
		// instruction to delete a case on a date counted from its CREATION,
		// while dossiq computes the legally correct date from the result type
		// and writes it to `case.archiveActionDate`. Two answers, one of them
		// wired to an hourly delete, is not a documentation problem.
		it(`${label}: carries no per-case-type destruction rules`, () => {
			expect(archival.retention.rules).toBeUndefined()
		})
	}

	// The second, independent failure, kept as a guard because it is the one a
	// future author is most likely to reintroduce while "fixing" the first.
	// The three rules that shipped cited omgevingsvergunning-regulier,
	// wmo-melding and subsidie-verlening. None of the three is a caseType this
	// app seeds, so every case fell through to the default regardless of the
	// UUID-versus-slug question underneath.
	it('would refuse a rule naming a case type nothing seeds', () => {
		const slugs = seededCaseTypeSlugs()
		expect(slugs.size).toBeGreaterThan(0)

		for (const parts of REGISTERS) {
			const register = readJson(...parts)
			const rules =
				register.components.schemas.case['x-openregister-archival'].retention
					.rules ?? []
			for (const rule of rules) {
				const cited = /"([^"]+)"/.exec(String(rule.condition ?? ''))
				if (cited === null) continue
				expect(
					slugs.has(cited[1]),
					`retention rule cites case type "${cited[1]}", which nothing seeds, so it can never match`,
				).toBe(true)
			}
		}
	})

	it('is the only schema carrying the annotation, so the blast radius is one table', () => {
		for (const parts of REGISTERS) {
			const register = readJson(...parts)
			const carriers = Object.entries(register.components.schemas)
				.filter(([, schema]) => 'x-openregister-archival' in schema)
				.map(([name]) => name)
			expect(carriers).toEqual(['case'])
		}
	})
})
