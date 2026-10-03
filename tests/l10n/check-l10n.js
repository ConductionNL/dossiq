#!/usr/bin/env node
/**
 * l10n extraction / drift check.
 *
 * Scans the frontend source for translation calls — t('<app>', '...'),
 * n('<app>', '...', '...', n) and the $t/$n template variants — and asserts
 * every literal source string is present as a key in l10n/en.json.
 *
 * This is the i18n equivalent of the Nextcloud `l10n` extraction step: it
 * guarantees l10n/en.json can never silently drift from the t() calls a
 * component actually makes, so a translatable string can't ship with no
 * English source entry (and therefore no entry for translators to pick up).
 *
 * It is intentionally dependency-free (pure Node, no build, no npm install)
 * so CI can run it in a bare node container, and devs can run it with
 * `node tests/l10n/check-l10n.js` from the app root.
 *
 * In addition to the used-key ⊂ en.json check, this script enforces
 * en.json ⇔ nl.json KEY-SET PARITY: every key in l10n/en.json MUST exist in
 * l10n/nl.json with a non-empty value (and vice versa). Without this, a new
 * English source string can ship with no Dutch translation and a Dutch-locale
 * user silently sees the raw English key as fallback text — the gap that
 * `nl-locale-coverage-gap-and-dutch-keys` closed. It also FAILS on the
 * Dutch-as-key anti-pattern: a USED key whose en.json value is identical to its
 * nl.json value renders Dutch to an English reader, unless the word is listed in
 * tests/l10n/language-neutral-keys.json as genuinely the same in both languages.
 * This docblock previously claimed to WARN on that while no such code existed.
 *
 * It also enforces CANONICAL KEY ORDER in both catalogues. That is a merge
 * concern rather than a translation one: while --write appended new keys after
 * the existing entries, every branch that added a string edited the same last
 * line, so every branch conflicted with every other one on every merge of
 * development. Sorted, new keys land beside their alphabetical neighbours and
 * the two edits never meet; what is left is resolved key by key by the git
 * merge driver in tools/merge-l10n.js.
 *
 * Modes:
 *   (default)  check only — exit non-zero if any used key is missing, the
 *              en/nl key sets diverge, OR a catalogue is out of order.
 *   --write    extraction — merge every missing used key into l10n/en.json
 *              as `"<source>": "<source>"` (English source === key, the
 *              Nextcloud convention) and write BOTH catalogues back in
 *              canonical order. This is the reproducible "run extraction"
 *              step; run it, review the diff, commit. The en/nl parity check
 *              is NOT auto-written (Dutch needs a human translation, not a
 *              self-map), but re-sorting is safe to automate: it adds no key
 *              and drops none.
 *
 * Exit codes:
 *   0  every used key is present in en.json, the en/nl key sets match, and
 *      both catalogues are in canonical order (or --write made them so)
 *   1  one or more used keys are missing from en.json, the en/nl key sets
 *      diverge, or a catalogue is out of order (hard failure)
 *
 * Env:
 *   L10N_APP_ID   override the app id (default: package.json "name")
 *   L10N_SRC_DIR  override the source dir to scan (default: src)
 *   L10N_FILE     override the en.json path (default: l10n/en.json)
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const fs = require('fs')
const path = require('path')
const { sortKeys } = require('../../scripts/build-l10n-js.js')

const ROOT = process.cwd()
const WRITE = process.argv.includes('--write')

function readJson (p) {
	return JSON.parse(fs.readFileSync(p, 'utf8'))
}

/**
 * Return the same translations in canonical order.
 *
 * The order itself lives in scripts/build-l10n-js.js, because the browser
 * catalogue generator and the git merge driver must agree with this writer
 * exactly: three writers with three orders would each undo the others.
 *
 * @param {object} translations - key -> translation
 * @return {object} a new object with the same entries, canonically ordered
 */
function sortCatalogue (translations) {
	const sorted = {}
	for (const key of sortKeys(Object.keys(translations))) {
		sorted[key] = translations[key]
	}
	return sorted
}

/**
 * Whether a catalogue's keys are already in canonical order.
 *
 * @param {object} translations - key -> translation
 * @return {boolean} true when nothing would move
 */
function isSorted (translations) {
	const keys = Object.keys(translations)
	const canonical = sortKeys(keys)
	return keys.every((key, i) => key === canonical[i])
}

const appId = process.env.L10N_APP_ID
	|| (fs.existsSync(path.join(ROOT, 'package.json'))
		? readJson(path.join(ROOT, 'package.json')).name
		: null)

if (!appId) {
	console.error('l10n-check: cannot determine app id (no L10N_APP_ID and no package.json "name")')
	process.exit(2)
}

const srcDir = path.join(ROOT, process.env.L10N_SRC_DIR || 'src')
const enFile = path.join(ROOT, process.env.L10N_FILE || 'l10n/en.json')

if (!fs.existsSync(srcDir)) {
	console.error(`l10n-check: source dir not found: ${srcDir}`)
	process.exit(2)
}
if (!fs.existsSync(enFile)) {
	console.error(`l10n-check: en.json not found: ${enFile} — every t() call would be a miss`)
	process.exit(1)
}

const translations = readJson(enFile).translations || {}

// Collect all .vue/.js/.ts/.mjs files under the source dir.
const exts = new Set(['.vue', '.js', '.ts', '.mjs', '.jsx', '.tsx'])
const files = []
;(function walk (dir) {
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		if (entry.name === 'node_modules' || entry.name.startsWith('.')) {
			continue
		}
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			walk(full)
		} else if (exts.has(path.extname(entry.name))) {
			files.push(full)
		}
	}
})(srcDir)

/**
 * Match t('<app>', '<key>') and n('<app>', '<singular>', '<plural>', ...),
 * plus the $t/$n template variants. Only LITERAL string arguments are
 * checkable — dynamic args (variables, concatenation, template literals
 * with ${}) are skipped, since their key isn't statically knowable.
 *
 * The app id and quote style (single, double, or back-tick without ${})
 * are matched explicitly so we don't pick up unrelated t() helpers.
 */
const esc = appId.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

// t('app', 'key'  — key in group 2 (any of the three quote styles).
const tRe = new RegExp(
	'[\\$.]?\\bt\\(\\s*[\'"`]' + esc + '[\'"`]\\s*,\\s*'
	+ '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|`([^`$]*)`)',
	'g',
)
// n('app', 'singular', 'plural'  — singular in 2, plural in 3.
const nRe = new RegExp(
	'[\\$.]?\\bn\\(\\s*[\'"`]' + esc + '[\'"`]\\s*,\\s*'
	+ '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|`([^`$]*)`)\\s*,\\s*'
	+ '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|`([^`$]*)`)',
	'g',
)

function unescape (s) {
	// Mirror JS string unescaping for the escapes that appear in source keys.
	return s
		.replace(/\\n/g, '\n')
		.replace(/\\t/g, '\t')
		.replace(/\\'/g, "'")
		.replace(/\\"/g, '"')
		.replace(/\\`/g, '`')
		// \uXXXX must decode, or the key recorded here is not the key t() looks up at
		// runtime. `t('dossiq', '{from} \u2014 (no end)')` was extracted as the literal
		// backslash-u text, so the catalogue held a key nothing ever requested and all
		// 35 translations of it were dead on arrival.
		.replace(/\\u([0-9a-fA-F]{4})/g, (_, h) => String.fromCharCode(parseInt(h, 16)))
		.replace(/\\\\/g, '\\')
}

// usedKey -> Set of "file:line" where it appears (for actionable output).
const used = new Map()

function record (key, file, idx, content) {
	if ((key === null || key === undefined)) {
		return
	}
	const k = unescape(key)
	const line = content.slice(0, idx).split('\n').length
	const where = `${path.relative(ROOT, file)}:${line}`
	if (!used.has(k)) {
		used.set(k, new Set())
	}
	used.get(k).add(where)
}

for (const file of files) {
	const content = fs.readFileSync(file, 'utf8')
	let m
	while ((m = tRe.exec(content)) !== null) {
		record(m[1] ?? m[2] ?? m[3], file, m.index, content)
	}
	while ((m = nRe.exec(content)) !== null) {
		record(m[1] ?? m[2] ?? m[3], file, m.index, content)
		record(m[4] ?? m[5] ?? m[6], file, m.index, content)
	}
}

// ---------------------------------------------------------------------------
// Manifest strings count as used keys.
//
// Scanning .vue/.js/.ts finds every `t('dossiq', '...')` call and nothing else,
// which is why this check passed for months while the app rendered English to
// Dutch users. The manifest is not source code the extractor reads; it is data
// the renderer walks. CnAppNav translates `menu[].label`, CnPageHeader
// translates a page's `title` and `description`, CnWalkthrough translates a
// step's `title` / `body` / `task` — all of them through the app's own
// translate function, all of them looking up a key that was never extracted.
//
// Measured 2026-08-26: 125 of this app's manifest strings had no nl.json key.
// Fleet-wide it was 1,939 of 2,965 (65%), and humaniq was the ONLY app whose
// parity checker read the manifest at all.
//
// Underscore-prefixed blocks are skipped: `_meta` is per-fragment provenance
// (spdx, change, adr), never rendered, and translating it would be nonsense.
// ---------------------------------------------------------------------------
const MANIFEST_TEXT_FIELDS = new Set([
	'title', 'body', 'task', 'label', 'description',
	'emptyText', 'placeholder', 'subtitle', 'helpText',
])

function recordManifestStrings (node, file, trail) {
	if (Array.isArray(node)) {
		node.forEach((v, i) => recordManifestStrings(v, file, `${trail}[${i}]`))
		return
	}
	if (node === null || typeof node !== 'object') {
		return
	}
	for (const [key, value] of Object.entries(node)) {
		if (key.startsWith('_')) {
			continue
		}
		const where = trail ? `${trail}.${key}` : key
		if (MANIFEST_TEXT_FIELDS.has(key) && typeof value === 'string' && value.trim()) {
			const k = value
			if (!used.has(k)) {
				used.set(k, new Set())
			}
			used.get(k).add(`${path.relative(ROOT, file)}:${where}`)
		} else {
			recordManifestStrings(value, file, where)
		}
	}
}

// The fragments matter as much as the manifest: src/manifest.d/*.json is merged
// in at runtime via require.context, so a checker reading only src/manifest.json
// is blind to whatever they add.
const manifestFiles = []
const mainManifest = path.join(ROOT, 'src', 'manifest.json')
if (fs.existsSync(mainManifest)) {
	manifestFiles.push(mainManifest)
}
const fragmentDir = path.join(ROOT, 'src', 'manifest.d')
if (fs.existsSync(fragmentDir)) {
	for (const name of fs.readdirSync(fragmentDir).sort()) {
		if (name.endsWith('.json')) {
			manifestFiles.push(path.join(fragmentDir, name))
		}
	}
}
for (const file of manifestFiles) {
	let parsed
	try {
		parsed = JSON.parse(fs.readFileSync(file, 'utf8'))
	} catch (e) {
		// A manifest that will not parse is check:manifest's finding, not this
		// script's. Say so rather than reporting a translation verdict over a
		// file that was never read.
		console.error(`[check-l10n] SKIP ${path.relative(ROOT, file)}: unreadable (${e.message})`)
		continue
	}
	recordManifestStrings(parsed, file, '')
}

// ---------------------------------------------------------------------------
// Dutch-as-key guard (REQ-I18N-04).
//
// A translation key is the ENGLISH SOURCE string. When a Dutch string is used as
// the key, l10n/en.json maps it to itself and an English UI renders Dutch. The
// docblock above claimed this was warned about since `nl-locale-coverage-gap-and-
// dutch-keys`; it never was, and 23 live keys shipped that way: `Commissie` and
// `Bezwaar` as column headers, `Kaartlaag` and `Subsidie` as page titles.
//
// The test is mechanical and needs no language detection. If en.json and nl.json
// hold the SAME value for a used key, the English catalogue offers a reader
// nothing the Dutch one does not. Either the word is the same in both languages,
// or the key is Dutch and untranslated. The first case is finite and listed in
// language-neutral-keys.json; everything else is the anti-pattern.
//
// The fix is NOT to change the key. Changing it orphans every other locale's
// translation of it. Give the Dutch key an English VALUE in l10n/en.json and
// leave the Dutch value in l10n/nl.json, the way 189 entries already do.
// ---------------------------------------------------------------------------
const neutralFile = path.join(ROOT, 'tests', 'l10n', 'language-neutral-keys.json')
const dutchNlFile = path.join(path.dirname(enFile), 'nl.json')
const dutchAsKey = []
let neutralChecked = false
if (fs.existsSync(dutchNlFile) && fs.existsSync(neutralFile)) {
	neutralChecked = true
	const nlAll = readJson(dutchNlFile).translations || {}
	const neutral = new Set(readJson(neutralFile).keys || [])
	for (const key of used.keys()) {
		if (neutral.has(key)) {
			continue
		}
		if (Object.hasOwn(translations, key)
			&& Object.hasOwn(nlAll, key)
			&& translations[key] === nlAll[key]) {
			dutchAsKey.push(key)
		}
	}
} else if (!fs.existsSync(neutralFile)) {
	console.warn(`l10n-check: WARN — ${path.relative(ROOT, neutralFile)} not found; skipping the Dutch-as-key guard`)
}

const missing = []
for (const [key, locations] of used) {
	if (!Object.hasOwn(translations, key)) {
		missing.push({ key, locations: [...locations] })
	}
}

console.log(`l10n-check [${appId}]: scanned ${files.length} files, `
	+ `${used.size} distinct literal keys used, `
	+ `${Object.keys(translations).length} keys in en.json`)

// ---------------------------------------------------------------------------
// en.json ⇔ nl.json parity (REQ-I18N-03). Every key in en.json MUST exist in
// nl.json with a non-empty value, and vice versa, so no user-visible string
// silently falls back to the raw key literal in the other locale.
// ---------------------------------------------------------------------------
const nlFile = path.join(path.dirname(enFile), 'nl.json')
const parityMissingInNl = []
const parityMissingInEn = []
const parityEmptyNl = []
let nlChecked = false
if (fs.existsSync(nlFile)) {
	nlChecked = true
	const nlTranslations = readJson(nlFile).translations || {}
	for (const key of Object.keys(translations)) {
		if (!Object.hasOwn(nlTranslations, key)) {
			parityMissingInNl.push(key)
		} else if (
			nlTranslations[key] === ''
			|| nlTranslations[key] === null
			|| nlTranslations[key] === undefined
		) {
			parityEmptyNl.push(key)
		}
	}
	for (const key of Object.keys(nlTranslations)) {
		if (!Object.hasOwn(translations, key)) {
			parityMissingInEn.push(key)
		}
	}
} else {
	console.warn(`l10n-check: WARN — nl.json not found at ${path.relative(ROOT, nlFile)}; skipping en/nl parity check`)
}

const parityFail = parityMissingInNl.length > 0 || parityMissingInEn.length > 0 || parityEmptyNl.length > 0
const dutchFail = dutchAsKey.length > 0

// ---------------------------------------------------------------------------
// Canonical order (merge-friendliness).
//
// The catalogues were append-only, so every key added by any branch landed on
// the same last line and every branch therefore conflicted with every other
// one. Sorted, a new key lands beside its alphabetical neighbours, two
// branches adding different strings touch different regions, and the merge
// driver in tools/merge-l10n.js can resolve what is left key by key.
//
// This is a CHECK and not only a --write behaviour, because a hand-edited
// Dutch translation appended at the end would quietly restore the old shape.
// Sorting invents no translation and drops none, so --write fixes it.
// ---------------------------------------------------------------------------
const orderTargets = [enFile, nlFile].filter((f) => fs.existsSync(f))
let unsorted = orderTargets.filter((file) => !isSorted(readJson(file).translations || {}))

if (WRITE && unsorted.length > 0) {
	for (const file of unsorted) {
		const doc = readJson(file)
		doc.translations = sortCatalogue(doc.translations || {})
		fs.writeFileSync(file, JSON.stringify(doc, null, 4) + '\n')
		console.log(`l10n-check: SORTED ${path.relative(ROOT, file)} into canonical order`)
	}
	unsorted = []
}

const orderFail = unsorted.length > 0

if (missing.length === 0 && !parityFail && !dutchFail && !orderFail) {
	console.log('l10n-check: OK — every used translation key is present in l10n/en.json')
	if (nlChecked) {
		console.log('l10n-check: OK — en.json and nl.json key sets match (no missing Dutch translations)')
	}
	if (neutralChecked) {
		console.log('l10n-check: OK — no used key renders Dutch to an English reader')
	}
	console.log('l10n-check: OK — both catalogues are in canonical order (merge-friendly)')
	process.exit(0)
}

if (WRITE && missing.length > 0) {
	// Extraction mode: merge the missing keys (source === English value) in and
	// write the whole catalogue back in canonical sorted order.
	//
	// This used to APPEND after the existing entries, "so the diff is purely
	// additive". It was additive and it was the reason every branch conflicted:
	// two branches that each add a string both write the same last line, so git
	// saw two edits to one region and refused the merge, every time. Measured
	// 2026-09-16, 14 of the last 20 merged PRs touched both catalogues and one
	// lane needed five merge cycles for one PR. Sorted, a new key lands beside
	// its alphabetical neighbours instead, and two branches adding different
	// strings edit different parts of the file.
	const full = readJson(enFile)
	const merged = { ...full.translations }
	for (const { key } of missing) {
		merged[key] = key
	}
	full.translations = sortCatalogue(merged)
	fs.writeFileSync(enFile, JSON.stringify(full, null, 4) + '\n')
	console.log(`l10n-check: WROTE ${missing.length} missing key(s) into `
		+ `${path.relative(ROOT, enFile)} (source === English value, sorted). `
		+ 'Review the diff and translate the nl.json side as needed.')
	// Fall through to the parity report so --write still surfaces nl gaps,
	// but never auto-exits 0 while the Dutch side is incomplete.
	if (!parityFail && !dutchFail) {
		process.exit(0)
	}
}

if (missing.length > 0 && !WRITE) {
	console.error(`\nl10n-check: FAIL — ${missing.length} translation key(s) used in source `
		+ 'but MISSING from l10n/en.json:')
	for (const { key, locations } of missing.sort((a, b) => a.key.localeCompare(b.key))) {
		console.error(`  • ${JSON.stringify(key)}`)
		for (const loc of locations.slice(0, 5)) {
			console.error(`      ${loc}`)
		}
		if (locations.length > 5) {
			console.error(`      … +${locations.length - 5} more`)
		}
	}
	console.error('\nAdd the missing source strings to l10n/en.json (key === English source), '
		+ 'or run `node tests/l10n/check-l10n.js --write` to extract them automatically.')
}

if (parityFail) {
	console.error('\nl10n-check: FAIL — l10n/en.json and l10n/nl.json key sets diverge.')
	if (parityMissingInNl.length > 0) {
		console.error(`\n  ${parityMissingInNl.length} key(s) in en.json but MISSING a Dutch translation in nl.json:`)
		for (const key of parityMissingInNl.sort((a, b) => a.localeCompare(b)).slice(0, 40)) {
			console.error(`  • ${JSON.stringify(key)}`)
		}
		if (parityMissingInNl.length > 40) {
			console.error(`  … +${parityMissingInNl.length - 40} more`)
		}
	}
	if (parityEmptyNl.length > 0) {
		console.error(`\n  ${parityEmptyNl.length} key(s) present in nl.json but with an EMPTY value:`)
		for (const key of parityEmptyNl.sort((a, b) => a.localeCompare(b)).slice(0, 40)) {
			console.error(`  • ${JSON.stringify(key)}`)
		}
	}
	if (parityMissingInEn.length > 0) {
		console.error(`\n  ${parityMissingInEn.length} key(s) in nl.json but ABSENT from en.json (stale — remove or add to en.json):`)
		for (const key of parityMissingInEn.sort((a, b) => a.localeCompare(b)).slice(0, 40)) {
			console.error(`  • ${JSON.stringify(key)}`)
		}
		if (parityMissingInEn.length > 40) {
			console.error(`  … +${parityMissingInEn.length - 40} more`)
		}
	}
	console.error('\nAdd the missing Dutch translations to l10n/nl.json so no Dutch-locale '
		+ 'string falls back to the raw English key.')
}

if (dutchFail) {
	console.error(`\nl10n-check: FAIL — ${dutchAsKey.length} used translation key(s) render Dutch to an English reader.`)
	console.error('l10n/en.json maps each of these to the very same text l10n/nl.json does, so an English UI shows the Dutch string:')
	for (const key of dutchAsKey.sort((a, b) => a.localeCompare(b))) {
		console.error(`  • ${JSON.stringify(key)}`)
		for (const loc of [...used.get(key)].slice(0, 3)) {
			console.error(`      ${loc}`)
		}
	}
	console.error('\nGive each key an English VALUE in l10n/en.json and keep the Dutch value in l10n/nl.json.')
	console.error('Do NOT rename the key: that orphans every other locale\'s translation of it.')
	console.error(`If the word really is identical in both languages, add it to ${path.relative(ROOT, neutralFile)}.`)
}

if (orderFail) {
	console.error(`\nl10n-check: FAIL — ${unsorted.length} catalogue(s) are not in canonical order:`)
	for (const file of unsorted) {
		console.error(`  • ${path.relative(ROOT, file)}`)
	}
	console.error('\nKeys appended at the end of a catalogue put every branch on the same last line, '
		+ 'so every branch conflicts with every other one.')
	console.error('Run `npm run test:l10n:write` to sort them. Sorting adds no key and drops none.')
}

process.exit((missing.length > 0 && !WRITE) || parityFail || dutchFail || orderFail ? 1 : 0)
