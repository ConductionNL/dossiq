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
 * 🔴 AND THEN THE KEY WAS REMOVED, which is the case the classifier CANNOT
 * tell apart on its own. Schema 2.40.0 drops `savedViewPlaces` (a saved view
 * is a lens, not a place). A removal the vendored copy has not caught up with
 * looks exactly like a lag — vendored declares it, installed does not — so
 * `check:manifest` would print PASS over a page still declaring a key the
 * library refuses. Nothing in the classifier can fix that, because the two
 * situations are the same shape. The version comparison below is the only
 * guard: re-vendor, and the removal stops reading as a lag.
 *
 * 🔴 A STALE VENDORED COPY FORGIVES A REMOVAL. Once the installed schema is
 * the NEWER of the two, a property the library has since DROPPED is still
 * declared in the vendored copy, and the classifier would read a genuine
 * breaking change as a lag and pass the build. So the vendored copy is kept
 * at or ahead of the installed one, and that is asserted below rather than
 * remembered.
 */
import { execFileSync, spawnSync } from 'node:child_process'
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

	it('has dropped the page key the mechanism was built around, in both copies', () => {
		const vendored = JSON.parse(fs.readFileSync(VENDORED, 'utf8'))
		const installed = JSON.parse(fs.readFileSync(INSTALLED, 'utf8'))

		// `savedViewPlaces` was the worked example this whole file was built
		// around: first as a lag (vendored declared it, no release did), then
		// as the lag that ended. Schema 2.40.0 REMOVES it, which is the third
		// state and the dangerous one — a removal the vendored copy has not
		// caught up with reads as a lag, and the classifier forgives it. Both
		// copies must have lost it, or a page still declaring it passes.
		expect(vendored.$defs.page.properties).not.toHaveProperty('savedViewPlaces')
		expect(installed.$defs.page.properties).not.toHaveProperty('savedViewPlaces')
	})

	it('refuses `_note` on an action in BOTH, which is why that error is real', () => {
		const vendored = JSON.parse(fs.readFileSync(VENDORED, 'utf8'))
		const installed = JSON.parse(fs.readFileSync(INSTALLED, 'utf8'))

		// The trap in one assertion: `_note` is all over this schema, and not
		// here. A classifier that searched for the name would forgive it.
		// An action's fields live in $defs/actionFields; $defs/action closes
		// them with unevaluatedProperties.
		expect(vendored.$defs.actionFields.properties).not.toHaveProperty('_note')
		expect(installed.$defs.actionFields.properties).not.toHaveProperty('_note')
		expect(vendored.$defs.action.properties).not.toHaveProperty('_note')
		expect(vendored.$defs.action.unevaluatedProperties).toBe(false)
	})
})

describe('check:manifest tells a lag from a defect', () => {
	// 30s on each of the three below, not the 5s default. Every one of them
	// SPAWNS the validator, and Ajv compiling the manifest schema over a
	// 62-page manifest is several seconds of real work per run. The default
	// was already being cleared by a margin that shrank with the manifest.
	it('the shipped manifest passes, forgiving only what the library lags', () => {
		// Both streams: the lag lines go to stderr, the verdict to stdout.
		// Reading stdout alone could never see a lag line, so the branch
		// that expects one could not pass, and it never ran until a lag came.
		const run = spawnSync('node', [VALIDATOR], { encoding: 'utf8' })
		expect(run.status).toBe(0)
		const out = String(run.stdout) + String(run.stderr)

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

		// The tour's `startOn` (schema 2.94.0) is derived the same way.
		const declaresStartOn = Object.hasOwn(
			installed.properties.walkthrough.properties.tours.items.properties || {},
			'startOn',
		)

		// The board card pill's `resolve` (schema 2.135.0) is derived the same way.
		const declaresCardResolve = Object.hasOwn(
			installed.$defs.boardView?.properties?.card?.properties || {},
			'resolve',
		)

		// The `redaction` page type (schema 2.137.0) is derived the same way.
		const declaresRedaction = (installed.$defs.page?.properties?.type?.enum || []).includes('redaction')

		// The generated quarter and year page filters (schema 2.138.0): a
		// value and an anyOf branch, not a property, so the newer schema is
		// asked about the path itself.
		const declaresGeneratedFilters = (installed.$defs.page?.properties?.config?.properties?.pageFilters?.items?.properties?.type?.enum || []).includes('quarter')

		// A page filter option's rolling window `months` (schema 2.147.0) is
		// derived the same way.
		const declaresOptionMonths = Object.hasOwn(
			installed.$defs.page?.properties?.config?.properties?.pageFilters?.items?.properties?.options?.items?.properties || {},
			'months',
		)

		// A page filter without a control (schema 2.151.0) is derived the same way.
		const declaresControlNone = (installed.$defs.page?.properties?.config?.properties?.pageFilters?.items?.properties?.control?.enum || []).includes('none')

		if (declaresAll && declaresStartOn && declaresCardResolve && declaresRedaction && declaresGeneratedFilters && declaresOptionMonths && declaresControlNone) {
			expect(out).not.toContain('pending a library release')
			return
		}

		expect(out).toContain('pending a library release')
		if (!declaresAll) {
			expect(out).toContain('visibleWhen uses "all"')
		}
		if (!declaresStartOn) {
			expect(out).toContain('uses "startOn"')
		}
		if (!declaresCardResolve) {
			expect(out).toContain('/config/board/card uses "resolve"')
		}
		if (!declaresGeneratedFilters) {
			expect(out).toMatch(/\/config\/pageFilters\/0\/type uses "enum", which schema [\d.]+ accepts/)
		}
		if (!declaresControlNone) {
			expect(out).toMatch(/\/config\/pageFilters\/\d+\/control uses "enum", which schema [\d.]+ accepts/)
		}
		if (!declaresOptionMonths) {
			expect(out).toMatch(/\/config\/pageFilters\/\d+\/options\/\d+ uses "additionalProperties", which schema [\d.]+ accepts/)
		}
	}, 30000)

	it('a value the newer schema refuses too is a defect, not a lag', () => {
		const manifest = JSON.parse(
			fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
		)
		const page = manifest.pages.find((candidate) => Array.isArray(candidate?.config?.pageFilters))
		page.config.pageFilters[0].type = 'fortnight'

		const result = validate(manifest)

		expect(result.code).toBe(1)
	})

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

	// @spec openspec/changes/the-getting-started-tour-starts-on-the-dashboard/specs/getting-started-tour/spec.md
	it('a tour key only the newer schema declares is a lag, and an unknown one is not', () => {
		const vendored = JSON.parse(fs.readFileSync(VENDORED, 'utf8'))
		const installed = JSON.parse(fs.readFileSync(INSTALLED, 'utf8'))
		const tourKeys = (schema) =>
			schema.properties.walkthrough.properties.tours.items.properties
		expect(Object.hasOwn(tourKeys(vendored), 'startOn')).toBe(true)

		const manifest = JSON.parse(
			fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
		)
		manifest.walkthrough.tours[0].startOn = ['Dashboard']
		const named = validate(manifest)
		expect(named.code).toBe(0)
		if (!Object.hasOwn(tourKeys(installed), 'startOn')) {
			expect(named.out).toContain('pending a library release')
		}

		manifest.walkthrough.tours[0].thisTourKeyExistsNowhere = true
		const bogus = validate(manifest)
		expect(bogus.code).toBe(1)
		expect(bogus.out).toContain('FAIL')
	}, 30000)

	it('a page type only the newer schema lists is a lag, and an unknown one is not', () => {
		const vendored = JSON.parse(fs.readFileSync(VENDORED, 'utf8'))
		expect(vendored.$defs.page.properties.type.enum).toContain('redaction')

		const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
		const named = validate(manifest)
		expect(named.code).toBe(0)

		manifest.pages.find((page) => page.id === 'CaseRedaction').type = 'thisPageTypeExistsNowhere'
		const bogus = validate(manifest)
		expect(bogus.code).toBe(1)
		expect(bogus.out).toContain('FAIL')
	}, 30000)

	// @spec openspec/specs/case-types/spec.md#requirement-a-case-type-carries-a-colour-and-an-icon
	it('a pill resolve key, a board card resolve or a view icon only the newer schema declares is a lag, and an unknown one is not', () => {
		const vendored = JSON.parse(fs.readFileSync(VENDORED, 'utf8'))
		expect(Object.hasOwn(vendored.$defs.headerPill.properties.resolve.properties, 'colorField')).toBe(true)

		const manifest = JSON.parse(
			fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
		)
		const page = manifest.pages.find((candidate) => candidate.type === 'detail')
		page.config.typePill = { field: 'caseType', resolve: { register: 'dossiq', schema: 'caseType', colorField: 'colour', iconField: 'icon' } }
		expect(validate(manifest).code).toBe(0)

		const cases = manifest.pages.find((candidate) => Array.isArray(candidate?.config?.defaultSavedViews))
		cases.config.defaultSavedViews[0].icon = 'FileDocumentOutline'
		expect(validate(manifest).code).toBe(0)

		const board = manifest.pages.find((candidate) => candidate?.config?.board?.card)
		board.config.board.card.resolve = { colorField: 'colour', iconField: 'icon' }
		expect(validate(manifest).code).toBe(0)

		page.config.typePill.resolve.thisKeyExistsNowhere = true
		const bogus = validate(manifest)
		expect(bogus.code).toBe(1)
		expect(bogus.out).toContain('FAIL')
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
