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
const MANIFEST_PATH = process.env.APP_MANIFEST
	|| path.join(REPO_ROOT, 'src', 'manifest.json')

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
	const vendored = path.join(REPO_ROOT, 'tests', 'schemas', 'app-manifest-v2.schema.json')
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

	const segments = String(instancePath || '').split('/').filter(Boolean)
	// The two shapes an unknown property can be rejected on today. Both are
	// resolved from the PATH, so a new one fails closed: unknown shape, not a
	// lag, and the error keeps failing the build until somebody teaches this.
	let def = null
	if (segments.length === 2 && segments[0] === 'pages') {
		def = schema.$defs.page
	} else if (
		segments.length === 4
		&& segments[0] === 'pages'
		&& segments[2] === 'config'
	) {
		def = null
	} else if (
		segments.length === 5
		&& segments[0] === 'pages'
		&& segments[2] === 'config'
		&& ['headerActions', 'actions', 'bulkActions', 'newActions'].includes(segments[3])
	) {
		def = schema.$defs.action
	}

	if (!def || !def.properties) {
		return false
	}

	return Object.hasOwn(def.properties, property)
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
		const errors = structuralLint(manifest)
		if (errors.length === 0) {
			console.log('[validate-manifest] structural lint: PASS (0 issues)')
			process.exit(0)
		}
		console.error('[validate-manifest] structural lint: FAIL')
		for (const err of errors) console.error(`  - ${err}`)
		process.exit(1)
	}
	console.log(`[validate-manifest] schema: ${schemaPath}`)
	const schema = loadJson(schemaPath)
	console.log(`[validate-manifest] schema.version: ${schema.version || '(unset)'}`)

	const { Ajv, addFormats } = loadAjv()
	if (!Ajv) {
		const errors = structuralLint(manifest)
		if (errors.length === 0) {
			console.log(
				'[validate-manifest] structural lint (no Ajv): PASS (0 issues)',
			)
			process.exit(0)
		}
		console.error('[validate-manifest] structural lint (no Ajv): FAIL')
		for (const err of errors) console.error(`  - ${err}`)
		process.exit(1)
	}

	const ajv = new Ajv({ allErrors: true, strict: false })
	if (addFormats) {
		try {
			addFormats(ajv)
		} catch (err) {
			console.warn(
				`[validate-manifest] ajv-formats failed to attach (${err.message}); continuing without format validation`,
			)
		}
	}
	const validate = ajv.compile(schema)
	const ok = validate(manifest)
	if (ok) {
		console.log('[validate-manifest] Ajv validation: PASS (0 errors)')
		process.exit(0)
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
	const lagged = []
	const real = []
	for (const err of validate.errors || []) {
		const property = err.params && err.params.additionalProperty
		if (
			err.keyword === 'additionalProperties'
			&& property
			&& newerSchemaAccepts(err.instancePath, property)
		) {
			lagged.push({ err, property })
			continue
		}
		real.push(err)
	}

	if (lagged.length > 0) {
		console.warn(
			`[validate-manifest] ${lagged.length} error(s) are the INSTALLED library lagging this manifest, not a defect here:`,
		)
		for (const { err, property } of lagged) {
			console.warn(
				`  ~ ${err.instancePath || '(root)'} uses "${property}", which schema ${newerSchemaVersion()} declares and the installed ${schemaVersion(schema)} does not`,
			)
		}
		console.warn(
			'[validate-manifest] Nothing in this app fixes those. They go when @conduction/nextcloud-vue '
			+ `releases a version carrying schema ${newerSchemaVersion()}.`,
		)
	}

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
