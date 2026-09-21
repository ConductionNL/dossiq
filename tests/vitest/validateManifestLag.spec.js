// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A library lag is not a defect in this manifest, and a defect is not a lag.
 *
 * 🔴 THE FAILURE THIS FILE EXISTS FOR IS A BLANKET EXCUSE. `check:manifest`
 * tells the two apart, and the tempting way to write that is a list of keys to
 * forgive, or a search for the property name anywhere in the newer schema.
 * Both would have waved through the one REAL error the mechanism found:
 * `_note` is declared on a dozen shapes in this schema and refused on
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
 *
 * ONE LAG ENDED AND ANOTHER BEGAN, WHICH IS THE POINT. `@conduction/
 * nextcloud-vue` 3.4.0 ships schema 2.37.0, which declares `savedViewPlaces`
 * and retired the lag this file was built around. Schema 2.39.0 then added
 * `all`/`any` to `visibleWhen`, which the claim and release gates on the case
 * detail page use and no release carries yet, so the vendored copy moved to
 * 2.39.0 and those two errors are forgiven until it does. Whether anything is
 * pending is READ from the installed schema below, never written down, so this
 * retires itself the day the release lands.
 *
 * 🔴 A STALE VENDORED COPY FORGIVES A REMOVAL. Once the installed schema is
 * the NEWER of the two, a property the library has since DROPPED is still
 * declared in the vendored copy, and the classifier would read a genuine
 * breaking change as a lag and pass the build. So the vendored copy is kept
 * at or ahead of the installed one, and that is asserted below rather than
 * remembered.
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

/**
 * Compare two dotted versions.
 *
 * @param {string} left  The left version.
 * @param {string} right The right version.
 * @return {number} Negative when left is older, 0 when equal, positive when newer.
 */
function compareVersions(left, right) {
	const a = String(left).split('.').map(Number)
	const b = String(right).split('.').map(Number)
	for (let i = 0; i < Math.max(a.length, b.length); i++) {
		const diff = (a[i] || 0) - (b[i] || 0)
		if (diff !== 0) {
			return diff
		}
	}
	return 0
}

describe('the vendored schema never falls behind the installed one', () => {
	it('is at or ahead of the installed schema, so nothing is forgiven wrongly', () => {
		const vendored = JSON.parse(fs.readFileSync(VENDORED, 'utf8'))
		const installed = JSON.parse(fs.readFileSync(INSTALLED, 'utf8'))

		// Behind, the classifier forgives a property the library REMOVED, and
		// a breaking change reads as a lag. Ahead or level, it can only
		// forgive a property the library has not shipped yet, which is what
		// it is for. So this is the direction that matters, not equality.
		expect(
			compareVersions(vendored.version, installed.version),
			`vendored ${vendored.version} is behind installed ${installed.version}: re-vendor tests/schemas/app-manifest-v2.schema.json from node_modules`,
		).toBeGreaterThanOrEqual(0)
	})

	it('has the page key whose release ended the lag', () => {
		const vendored = JSON.parse(fs.readFileSync(VENDORED, 'utf8'))
		const installed = JSON.parse(fs.readFileSync(INSTALLED, 'utf8'))

		// `savedViewPlaces` is the property the whole mechanism was built
		// around. The installed library now declares it, which is what
		// retired the lag, and the vendored copy must not have lost it.
		expect(vendored.$defs.page.properties).toHaveProperty('savedViewPlaces')
		expect(installed.$defs.page.properties).toHaveProperty('savedViewPlaces')
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
	it('the shipped manifest passes, forgiving only what the library lags', () => {
		const out = execFileSync('node', [VALIDATOR], { encoding: 'utf8' })

		expect(out).toContain('PASS')

		// Derived, not pinned: the manifest's claim and release gates use
		// `visibleWhen.all`, so a release that does not declare it yet is a
		// lag and one that does leaves nothing to forgive. Asserting the
		// absence in that second case is what keeps a NEW lag from being
		// inherited without anybody looking at it.
		const installed = JSON.parse(fs.readFileSync(INSTALLED, 'utf8'))
		const declaresAll = Object.hasOwn(
			installed.$defs.visibleWhen.properties || {},
			'all',
		)

		if (declaresAll) {
			expect(out).not.toContain('pending a library release')
			return
		}

		expect(out).toContain('pending a library release')
		expect(out).toContain('visibleWhen uses "all"')
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
