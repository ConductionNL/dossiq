#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-manifest.js — schema-validates src/manifest.json against the
// @conduction/nextcloud-vue v2 app-manifest schema using Ajv.
//
// Usage:
//   node tests/validate-manifest.js
//
// Exit codes:
//   0 — manifest validates against the schema with zero errors
//   1 — manifest fails validation (or schema/manifest cannot be loaded)
//
// src/manifest.json declares the v2 schema ($schema → app-manifest-v2.schema.json),
// so we validate against v2. The canonical v2 schema is vendored under
// tests/schemas/ so the gate is self-contained (CI does not depend on a fresh
// node_modules copy, and the published @conduction/nextcloud-vue v2 schema can
// lag the canonical hydra one — e.g. the metric `cacheTtl` property).
//
// Schema lookup order (first hit wins). CORRECTED 2026-09-18: this list had the
// vendored copy second and the installed package third, which is the order the
// code STOPPED using when somebody fixed the drift machine described below. A
// comment that contradicts the code beside it is worse than no comment, and this
// one had the two authorities the wrong way round.
//   1. Env var APP_MANIFEST_SCHEMA — explicit absolute path to a schema JSON
//   2. node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json
//   3. tests/schemas/app-manifest-v2.schema.json (vendored, newer, also the
//      reference this file uses to tell a library lag from a real defect)
//   4. ../nextcloud-vue/src/schemas/app-manifest-v2.schema.json (sibling worktree)
//
// The manifest under test is src/manifest.json, overridable with APP_MANIFEST.

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')

// `APP_MANIFEST` overrides the file under test, the way `APP_MANIFEST_SCHEMA`
// already overrides the schema. Added so `validateManifestLag.spec.js` can put
// a deliberate defect in front of the classifier without editing the shipped
// manifest, which is the only way to assert that a real error still fails.
const MANIFEST_PATH =
	process.env.APP_MANIFEST || path.join(REPO_ROOT, 'src', 'manifest.json')

// THE INSTALLED SCHEMA WINS OVER THE VENDORED COPY.
//
// It used to be the other way round, and that ordering is a drift machine: the
// vendored copy shadows the one the shipped runtime actually enforces, so the
// check keeps passing against a snapshot of the rules while the rules move.
// Measured here — the vendored copy sat at 2.25.0 and rejected `type: "flow"`,
// which the installed 2.20.0 package (schema 2.26.0) accepts and the runtime
// registers. The manifest was right and the check was reading last month's
// grammar.
//
// A manifest has to satisfy the schema its own dependency ships. The vendored
// copy stays as a fallback for a tree with no node_modules — a fresh checkout,
// a CI leg that skips install — but it no longer gets to overrule what is
// installed.
const SCHEMA_CANDIDATES = [
	process.env.APP_MANIFEST_SCHEMA,
	path.join(
		REPO_ROOT,
		'node_modules',
		'@conduction',
		'nextcloud-vue',
		'src',
		'schemas',
		'app-manifest-v2.schema.json',
	),
	path.join(REPO_ROOT, 'tests', 'schemas', 'app-manifest-v2.schema.json'),
	path.join(
		REPO_ROOT,
		'..',
		'nextcloud-vue',
		'src',
		'schemas',
		'app-manifest-v2.schema.json',
	),
].filter(Boolean)

function findSchemaPath() {
	for (const candidate of SCHEMA_CANDIDATES) {
		try {
			if (fs.existsSync(candidate) && fs.statSync(candidate).isFile()) {
				return candidate
			}
		} catch (_) {
			// continue to next candidate
		}
	}
	return null
}

function loadJson(file) {
	const raw = fs.readFileSync(file, 'utf8')
	return JSON.parse(raw)
}

/**
 * The newer schema this repo vendors, used ONLY to tell a library lag from a
 * defect in this manifest. It never validates anything: the installed schema
 * stays the authority, for the reason the SCHEMA_CANDIDATES comment gives.
 *
 * @return {object|null} The vendored schema, or null when there is none.
 */
function newerSchema() {
	if (newerSchema.cached !== undefined) {
		return newerSchema.cached
	}
	const vendored = path.join(
		REPO_ROOT,
		'tests',
		'schemas',
		'app-manifest-v2.schema.json',
	)
	try {
		newerSchema.cached = fs.existsSync(vendored) ? loadJson(vendored) : null
	} catch (_) {
		newerSchema.cached = null
	}
	return newerSchema.cached
}

/**
 * A schema's declared version, for saying which one is which out loud.
 *
 * @param {object|null} schema The schema.
 * @return {string} Its version, or 'unknown'.
 */
function schemaVersion(schema) {
	return (schema && schema.version) || 'unknown'
}

/**
 * The version of the vendored newer schema.
 *
 * @return {string} Its version.
 */
function newerSchemaVersion() {
	return schemaVersion(newerSchema())
}

/**
 * The page type an `enum` error on `/pages/<n>/type` rejected, when the vendored
 * newer schema lists it; '' for any other error.
 *
 * @param {object} err      An Ajv error.
 * @param {object} manifest The manifest under validation.
 * @return {string} The page type, or ''.
 */
function newerPageType(err, manifest) {
	const match = /^\/pages\/(\d+)\/type$/.exec(String(err.instancePath || ''))
	if (err.keyword !== 'enum' || !match) {
		return ''
	}
	const type = manifest?.pages?.[Number(match[1])]?.type
	const listed = newerSchema()?.$defs?.page?.properties?.type?.enum
	return typeof type === 'string' && Array.isArray(listed) && listed.includes(type) ? type : ''
}

const ACTION_LISTS = ['headerActions', 'actions', 'bulkActions', 'newActions']

/**
 * Whether `segments` is `pages/<n>/config/<key>/…` with `length` segments.
 *
 * @param {string[]} segments The instance path, split.
 * @param {number}   length   The required segment count.
 * @return {boolean} True when it is a page-config path of that length.
 */
function isPageConfigPath(segments, length) {
	return segments.length === length && segments[0] === 'pages' && segments[2] === 'config'
}

/**
 * The shapes an unknown property can be rejected on, each resolved from the
 * PATH, so a new one fails closed: unknown shape, not a lag, and the error
 * keeps failing the build until somebody teaches this table. The first entry
 * whose `matches` holds decides.
 */
const NEWER_SCHEMA_SHAPES = [
	{
		matches: (seg) => seg.length === 2 && seg[0] === 'pages',
		def: (schema) => schema.$defs.page,
	},
	{
		matches: (seg) => isPageConfigPath(seg, 5) && ACTION_LISTS.includes(seg[3]),
		def: (schema) => schema.$defs.action,
	},
	{
		matches: (seg) => isPageConfigPath(seg, 6) && ACTION_LISTS.includes(seg[3]) && seg[5] === 'visibleWhen',
		def: (schema) => schema.$defs.visibleWhen,
	},
	// A detail header pill's `resolve` (pill-color-field, schema 2.134.0).
	{
		matches: (seg) => isPageConfigPath(seg, 5) && ['typePill', 'statusPill'].includes(seg[3]) && seg[4] === 'resolve',
		def: (schema) => schema.$defs.headerPill?.properties?.resolve,
	},
	// A board card (its pill's `resolve`, pill-color-field, schema 2.135.0).
	{
		matches: (seg) => isPageConfigPath(seg, 5) && seg[3] === 'board' && seg[4] === 'card',
		def: (schema) => schema.$defs.boardView?.properties?.card,
	},
	// A page's default saved view (its `icon`, pill-color-field, schema 2.134.0).
	{
		matches: (seg) => isPageConfigPath(seg, 5) && seg[3] === 'defaultSavedViews',
		def: (schema) => schema.$defs.page?.properties?.config?.properties?.defaultSavedViews?.items,
	},
	// A walkthrough tour is declared inline, not under $defs (the-getting-
	// started-tour-starts-on-the-dashboard, `startOn` in schema 2.94.0).
	{
		matches: (seg) => seg.length === 3 && seg[0] === 'walkthrough' && seg[1] === 'tours',
		def: (schema) => schema.properties?.walkthrough?.properties?.tours?.items,
	},
]

/**
 * Whether the vendored newer schema declares `property` at `instancePath`.
 *
 * 🔑 IT RESOLVES THE PATH RATHER THAN SEARCHING FOR THE NAME. `_note` is
 * declared on a dozen shapes in this schema and refused on `$defs/action`, so a
 * name search would call the one real error a lag and wave it through. The path
 * is walked against the manifest and the matching definition is looked up, so
 * the question asked is the one that matters: may THIS shape carry it.
 *
 * @param {string} instancePath The failing instance path, e.g. `/pages/3`.
 * @param {string} property     The rejected property name.
 * @return {boolean} True when the newer schema accepts it there.
 */
function newerSchemaAccepts(instancePath, property) {
	const schema = newerSchema()
	if (!schema || !schema.$defs) {
		return false
	}
	const segments = String(instancePath || '')
		.split('/')
		.filter(Boolean)
	const shape = NEWER_SCHEMA_SHAPES.find((candidate) => candidate.matches(segments))
	const def = shape ? shape.def(schema) : null
	if (!def || !def.properties) {
		return false
	}
	return Object.hasOwn(def.properties, property)
}

/**
 * Compare two `major.minor.patch` versions.
 *
 * @param {string} left The left version.
 * @param {string} right The right version.
 * @return {number} Negative when left is older, 0 when equal, positive when newer.
 */
function compareSchemaVersions(left, right) {
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

function loadAjv() {
	// The canonical schema uses JSON Schema draft 2020-12 (`$schema`:
	// "https://json-schema.org/draft/2020-12/schema"). Standard Ajv (v7+)
	// does not auto-load the 2020 meta-schema; we need the `ajv/dist/2020`
	// entry point. Prefer Ajv 8 (`ajv/dist/2020`) when available; otherwise
	// fall back to whichever ajv resolves first.
	let Ajv2020 = null
	let addFormats
	const ajvCandidates = [
		'ajv/dist/2020',
		path.join(
			REPO_ROOT,
			'node_modules',
			'ajv-formats',
			'node_modules',
			'ajv',
			'dist',
			'2020.js',
		),
		'ajv',
	]
	for (const candidate of ajvCandidates) {
		try {
			const mod = require(candidate)
			Ajv2020 = mod.default || mod
			break
		} catch (_) {
			// next candidate
		}
	}
	if (!Ajv2020) {
		console.error('[validate-manifest] Ajv not installed in node_modules.')
		console.error('[validate-manifest] Install with: npm i -D ajv ajv-formats')
		console.error('[validate-manifest] Falling back to a structural lint pass.')
		return { Ajv: null, addFormats: null }
	}
	try {
		const mod = require('ajv-formats')
		addFormats = mod.default || mod
	} catch (_) {
		addFormats = null
	}
	return { Ajv: Ajv2020, addFormats }
}

function structuralLint(manifest) {
	const errors = []
	if (!manifest.version || typeof manifest.version !== 'string') {
		errors.push('top-level: version (string) is required')
	}
	if (!Array.isArray(manifest.menu))
		errors.push('top-level: menu (array) is required')
	if (!Array.isArray(manifest.pages))
		errors.push('top-level: pages (array) is required')
	const allowedTypes = new Set([
		'index',
		'detail',
		'dashboard',
		'logs',
		'settings',
		'chat',
		'files',
		'custom',
	])
	const seenIds = new Set()
	for (let i = 0; i < (manifest.pages || []).length; i++) {
		const page = manifest.pages[i]
		if (!page || typeof page !== 'object') {
			errors.push(`pages[${i}]: must be an object`)
			continue
		}
		for (const required of ['id', 'route', 'type', 'title']) {
			if (!page[required] || typeof page[required] !== 'string') {
				errors.push(
					`pages[${i}]: missing required string field "${required}"`,
				)
			}
		}
		if (page.type && !allowedTypes.has(page.type)) {
			errors.push(`pages[${i}].type: "${page.type}" not in v1.2 enum`)
		}
		if (page.id) {
			if (seenIds.has(page.id))
				errors.push(`pages[${i}].id: duplicate "${page.id}"`)
			seenIds.add(page.id)
		}
		if (page.type === 'custom' && !page.component) {
			errors.push(`pages[${i}]: type=custom requires component field`)
		}
	}
	return errors
}

/**
 * Run the structural lint and exit with its verdict.
 *
 * @param {object} manifest The manifest.
 * @param {string} label    How the lint is named in the output.
 * @return {void}
 */
function exitWithStructuralLint(manifest, label) {
	const errors = structuralLint(manifest)
	if (errors.length === 0) {
		console.log(`[validate-manifest] ${label}: PASS (0 issues)`)
		process.exit(0)
	}
	console.error(`[validate-manifest] ${label}: FAIL`)
	for (const err of errors) console.error(`  - ${err}`)
	process.exit(1)
}

/**
 * A draft 2020-12 Ajv instance, with formats attached when ajv-formats loads.
 *
 * @param {Function}      Ajv            The Ajv constructor.
 * @param {Function|null} addFormats     The ajv-formats plugin, or null.
 * @param {Function}      onFormatError  Called with the error when formats fail to attach.
 * @return {object} The Ajv instance.
 */
function newAjv(Ajv, addFormats, onFormatError) {
	const ajv = new Ajv({ allErrors: true, strict: false })
	if (addFormats) {
		try {
			addFormats(ajv)
		} catch (err) {
			onFormatError(err)
		}
	}
	return ajv
}

// EVERY ERROR IS ONE OF TWO THINGS, AND THEY NEED DIFFERENT PEOPLE.
//
// Either the manifest is wrong, which an app author fixes, or the INSTALLED
// library is older than the schema this manifest targets, which only a
// library release fixes and which no app author can do anything about.
// Reported as one undifferentiated list they are indistinguishable, and
// measured on 2026-09-18 that cost every lane on the integration branch a
// red `check:manifest` and a paragraph in every PR body: three errors, two
// of them a lag on `savedViewPlaces` (schema 2.34.0, shipped in no release
// yet) and one of them a real `_note` on a header action that had been
// hiding behind them since it landed.
//
// The classification is DERIVED, never written down: an unknown-property
// error is a lag only when the newer schema this repo vendors actually
// declares that property. So it cannot become a blanket excuse, and it
// stops being a lag by itself the day the release lands.
//
// 🔴 `savedViewPlaces` above is kept because it is the MEASURED example,
// but the key itself was later removed from the vocabulary (schema 2.40.0:
// a saved view is a lens applied onto the page's own address, not a place).
// That is the third case, and this classifier cannot see it: a REMOVAL the
// vendored copy has not caught up with is the same shape as a lag, so a
// page still declaring a retired key would be forgiven and reported as
// PASS. Re-vendoring is the only thing that tells them apart, and
// `tests/vitest/validateManifestLag.spec.js` is what insists on it.
/**
 * Split Ajv's errors into the installed library lagging this manifest and
 * defects of the manifest itself, by asking the vendored newer schema.
 *
 * @param {object[]} errors   Ajv errors against the installed schema.
 * @param {object}   manifest The manifest.
 * @return {{lagged: object[], real: object[]}} The two lists.
 */
function classifyErrors(errors, manifest) {
	const lagged = []
	const real = []
	for (const err of errors) {
		const property = err.params && err.params.additionalProperty
		if (
			err.keyword === 'additionalProperties'
			&& property
			&& newerSchemaAccepts(err.instancePath, property)
		) {
			lagged.push({ err, property })
			continue
		}
		// A page TYPE only the newer schema lists (`redaction`, schema 2.137.0)
		// is the same lag, one level down: the enum, not a property.
		const pageType = newerPageType(err, manifest)
		if (pageType !== '') {
			lagged.push({ err, property: `type: ${pageType}` })
			continue
		}
		real.push(err)
	}
	return { lagged, real }
}

/**
 * The same question for a VALUE or a branch the newer schema allows (a new
 * enum value, a new anyOf branch): there is no property name to look up, so
 * the newer schema is asked directly. An error is a lag only when the newer
 * schema reports nothing at its path, below it or above it; any newer error
 * near it keeps it a defect. Moves such errors from `real` to `lagged`.
 *
 * @param {object}   context  `{Ajv, addFormats, schema, manifest}`.
 * @param {object[]} lagged   Lagging errors; gains the moved ones.
 * @param {object[]} real     Defects; loses the moved ones.
 * @return {void}
 */
function reclassifyByNewerSchema(context, lagged, real) {
	const newer = newerSchema()
	if (!newer || real.length === 0 || compareSchemaVersions(schemaVersion(newer), schemaVersion(context.schema)) <= 0) {
		return
	}
	// formats are not what tells a lag from a defect
	const validateNewer = newAjv(context.Ajv, context.addFormats, () => {}).compile(newer)
	validateNewer(context.manifest)
	const newerPaths = (validateNewer.errors || []).map((e) => e.instancePath || '')
	const near = (at) => newerPaths.some((p) => p === at || p.startsWith(at + '/') || at.startsWith(p + '/') || p === '')
	for (let i = real.length - 1; i >= 0; i--) {
		const at = real[i].instancePath || ''
		if (at !== '' && !near(at)) {
			lagged.push({ err: real[i], property: real[i].keyword })
			real.splice(i, 1)
		}
	}
}

/**
 * A shape checked inside an `if`/`then` (a board under `type: index`) reports
 * its lag twice: the property, and the `then` it sits under on an ancestor
 * path. The wrapper is that same lag, not a defect of its own; a real error
 * under the same page is reported separately and still fails.
 *
 * @param {object[]} lagged Lagging errors.
 * @param {object[]} real   Defects; loses the `if` wrappers of a lag.
 * @return {void}
 */
function dropLaggedWrappers(lagged, real) {
	const laggedPaths = lagged.map(({ err }) => err.instancePath || '')
	for (let i = real.length - 1; i >= 0; i--) {
		const err = real[i]
		const at = err.instancePath || ''
		if (err.keyword === 'if' && laggedPaths.some((lagPath) => lagPath.startsWith(at + '/'))) {
			real.splice(i, 1)
		}
	}
}

/**
 * Warn about the errors that are the installed library lagging this manifest.
 *
 * @param {object[]} lagged Lagging errors.
 * @param {object}   schema The installed schema.
 * @return {void}
 */
function reportLagged(lagged, schema) {
	if (lagged.length === 0) {
		return
	}
	console.warn(
		`[validate-manifest] ${lagged.length} error(s) are the INSTALLED library lagging this manifest, not a defect here:`,
	)
	for (const { err, property } of lagged) {
		console.warn(
			`  ~ ${err.instancePath || '(root)'} uses "${property}", which schema ${newerSchemaVersion()} accepts and the installed ${schemaVersion(schema)} does not`,
		)
	}
	console.warn(
		'[validate-manifest] Nothing in this app fixes those. They go when @conduction/nextcloud-vue '
			+ `releases a version carrying schema ${newerSchemaVersion()}.`,
	)
}

function main() {
	if (!fs.existsSync(MANIFEST_PATH)) {
		console.error(`[validate-manifest] manifest not found: ${MANIFEST_PATH}`)
		process.exit(1)
	}

	const manifest = loadJson(MANIFEST_PATH)
	console.log(`[validate-manifest] manifest: ${MANIFEST_PATH}`)
	console.log(`[validate-manifest] manifest.version: ${manifest.version}`)
	console.log(`[validate-manifest] pages: ${(manifest.pages || []).length}`)

	const schemaPath = findSchemaPath()
	if (!schemaPath) {
		console.warn(
			'[validate-manifest] no schema candidate resolved; falling back to structural lint.',
		)
		exitWithStructuralLint(manifest, 'structural lint')
	}
	console.log(`[validate-manifest] schema: ${schemaPath}`)
	const schema = loadJson(schemaPath)
	console.log(`[validate-manifest] schema.version: ${schema.version || '(unset)'}`)

	const { Ajv, addFormats } = loadAjv()
	if (!Ajv) {
		exitWithStructuralLint(manifest, 'structural lint (no Ajv)')
	}

	const validate = newAjv(Ajv, addFormats, (err) => {
		console.warn(
			`[validate-manifest] ajv-formats failed to attach (${err.message}); continuing without format validation`,
		)
	}).compile(schema)
	if (validate(manifest)) {
		console.log('[validate-manifest] Ajv validation: PASS (0 errors)')
		process.exit(0)
	}

	const { lagged, real } = classifyErrors(validate.errors || [], manifest)
	reclassifyByNewerSchema({ Ajv, addFormats, schema, manifest }, lagged, real)
	dropLaggedWrappers(lagged, real)
	reportLagged(lagged, schema)

	if (real.length === 0) {
		console.log(
			`[validate-manifest] Ajv validation: PASS (0 errors of this app's own, ${lagged.length} pending a library release)`,
		)
		process.exit(0)
	}

	console.error('[validate-manifest] Ajv validation: FAIL')
	for (const err of real) {
		console.error(
			`  - ${err.instancePath || '(root)'} ${err.message} (keyword=${err.keyword})`,
		)
	}
	process.exit(1)
}

main()
