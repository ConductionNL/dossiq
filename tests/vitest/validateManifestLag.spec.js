// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A library lag is not a defect in this manifest, and a defect is not a lag.
 *
 * 🔴 THE FAILURE THIS FILE EXISTS FOR IS A BLANKET EXCUSE. `check:manifest` now
 * tells the two apart, and the tempting way to write that is a list of keys to
 * forgive, or a search for the property name anywhere in the newer schema.
 * Both would have waved through the one REAL error this change found:
 * `_note` is declared on a dozen shapes in schema 2.34.0 and refused on
 * `$defs/action`, so a name search calls a genuine defect a lag and the build
 * goes green over it.
 *
 * Measured 2026-09-18 on `parity/round2`: three errors, two a lag on
 * `savedViewPlaces` and one a real `_note` on a header action that had been
 * hiding behind them since it landed. Every lane inherited all three and
 * reported them as one paragraph.
 *
 * So the classifier resolves the INSTANCE PATH to a definition and asks whether
 * that shape declares the property. These tests are what keep it that way.
 */
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const VALIDATOR = path.join(ROOT, 'tests', 'validate-manifest.js')
const VENDORED = path.join(ROOT, 'tests', 'schemas', 'app-manifest-v2.schema.json')
const INSTALLED = path.join(
	ROOT,
	'node_modules',
	'@conduction',
	'nextcloud-vue',
	'src',
	'schemas',
	'app-manifest-v2.schema.json',
)

/**
 * Run the validator over a manifest written to a temporary file.
 *
 * @param {object} manifest The manifest to validate.
 * @return {{code: number, out: string}} The exit code and the combined output.
 */
function validate(manifest) {
	const tmp = path.join(ROOT, 'tests', `.manifest-lag-${process.pid}.json`)
	fs.writeFileSync(tmp, JSON.stringify(manifest, null, '\t'))
	try {
		const out = execFileSync('node', [VALIDATOR], {
			env: {
				...process.env,
				APP_MANIFEST_SCHEMA: INSTALLED,
				APP_MANIFEST: tmp,
			},
			encoding: 'utf8',
			stdio: ['ignore', 'pipe', 'pipe'],
		})
		return { code: 0, out }
	} catch (error) {
		return {
			code: error.status ?? 1,
			out: String(error.stdout ?? '') + String(error.stderr ?? ''),
		}
	} finally {
		fs.rmSync(tmp, { force: true })
	}
}

describe('the vendored schema is newer than the installed one', () => {
	it('is what makes the classification possible at all', () => {
		const vendored = JSON.parse(fs.readFileSync(VENDORED, 'utf8'))
		const installed = JSON.parse(fs.readFileSync(INSTALLED, 'utf8'))

		// If these ever match, there is no lag to classify and the whole
		// mechanism is inert. It would then pass on everything, silently,
		// which is the shape this suite exists to refuse.
		expect(vendored.version).not.toBe(installed.version)
	})

	it('declares the page key the installed one refuses', () => {
		const vendored = JSON.parse(fs.readFileSync(VENDORED, 'utf8'))
		const installed = JSON.parse(fs.readFileSync(INSTALLED, 'utf8'))

		expect(vendored.$defs.page.properties).toHaveProperty('savedViewPlaces')
		expect(installed.$defs.page.properties).not.toHaveProperty('savedViewPlaces')
	})

	it('refuses `_note` on an action in BOTH, which is why that error is real', () => {
		const vendored = JSON.parse(fs.readFileSync(VENDORED, 'utf8'))
		const installed = JSON.parse(fs.readFileSync(INSTALLED, 'utf8'))

		// The trap in one assertion: `_note` is all over this schema, and not
		// here. A classifier that searched for the name would forgive it.
		expect(vendored.$defs.action.properties).not.toHaveProperty('_note')
		expect(installed.$defs.action.properties).not.toHaveProperty('_note')
		expect(vendored.$defs.action.additionalProperties).toBe(false)
	})
})

describe('check:manifest tells a lag from a defect', () => {
	// 30s on each of the three below, not the 5s default. Every one of them
	// SPAWNS the validator, and Ajv compiling the manifest schema over a
	// 62-page manifest is several seconds of real work per run. The default
	// was already being cleared by a margin that shrank with the manifest.
	it('the shipped manifest passes, because its only errors are the lag', () => {
		const out = execFileSync('node', [VALIDATOR], { encoding: 'utf8' })

		expect(out).toContain('PASS')
		expect(out).toContain('pending a library release')
	}, 30000)

	it('a defect on a shape the newer schema also refuses still fails', () => {
		const manifest = JSON.parse(
			fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
		)
		const page = manifest.pages.find((candidate) =>
			Array.isArray(candidate?.config?.headerActions),
		)
		expect(
			page,
			'a page with header actions must exist, or this measures nothing',
		).toBeTruthy()
		page.config.headerActions[0]._note = 'a key $defs/action refuses'

		const result = validate(manifest)

		expect(result.code).toBe(1)
		expect(result.out).toContain('FAIL')
	}, 30000)

	it('a key NEITHER schema knows fails, rather than being forgiven as a lag', () => {
		const manifest = JSON.parse(
			fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
		)
		manifest.pages[0].thisKeyExistsNowhere = true

		const result = validate(manifest)

		expect(result.code).toBe(1)
		expect(result.out).toContain('FAIL')
	}, 30000)
})
