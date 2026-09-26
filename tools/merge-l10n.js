#!/usr/bin/env node
/**
 * Git merge driver for the l10n catalogues.
 *
 * WHY THIS EXISTS
 * ---------------
 * `l10n/en.json` and `l10n/nl.json` were append-only: `tests/l10n/check-l10n.js
 * --write` added every newly extracted key AFTER the existing entries, so two
 * branches that each add a string both write to the same last line of the same
 * file. Git sees two different edits to one region and calls it a conflict,
 * every time, on every merge of development. Measured on 2026-09-16: 14 of the
 * last 20 merged dossiq PRs touched both catalogues, and one lane reported five
 * merge cycles for a single PR. The generated `l10n/en.js` and `l10n/nl.js`
 * inherit the order of their source, so they conflicted on the same line too.
 *
 * Sorting the catalogues (this change does that as well) removes most of the
 * pain: "Add a case" and "Zoom to fit" land thousands of lines apart, so the
 * two edits no longer meet. It does not remove all of it. Two keys that sort
 * next to each other still collide, and a line-based merge cannot do better,
 * because it does not know the file is a set of keys.
 *
 * This driver does. It merges the three versions KEY BY KEY, which is the
 * operation people actually mean: a key added on one side is kept, a key
 * deleted on one side is dropped, a key whose translation only one side
 * changed takes that side's text, and only a key that BOTH sides translated
 * differently is a real conflict.
 *
 * WHY NOT `merge=union`
 * ---------------------
 * `union` concatenates both hunks. On JSON that produces `"a": "b"` directly
 * followed by `"c": "d"` with no comma between them, or two closing braces, so
 * the catalogue stops parsing and every string in the app falls back to its raw
 * key. `union` is only safe for line-oriented files with no syntax between
 * lines, which a JSON object is not.
 *
 * HOW A CONFLICT IS REPORTED
 * --------------------------
 * With standard `<<<<<<< / ======= / >>>>>>>` markers around the two candidate
 * lines, and exit code 1 so git records the path as unmerged. The file then
 * does not parse, which is deliberate: `npm run test:l10n` fails loudly and the
 * conflict cannot be staged unnoticed. Writing our side silently and exiting 0
 * would drop the other branch's translation with nothing on screen.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * It never invents a translation. A key only one side added keeps exactly that
 * side's value, and a genuinely contradictory pair is handed back to a human.
 *
 * REGISTRATION
 * ------------
 * `.gitattributes` names the driver per path; the driver's command line is
 * LOCAL config, because git deliberately refuses to run a command a repository
 * ships (that would let a fetched branch execute code on checkout).
 * `npm install` registers it through the `prepare` script; an existing clone
 * registers it with the one line in docs/Technical/l10n-merge-driver.md.
 *
 * USAGE (this is what git invokes)
 *   node tools/merge-l10n.js %O %A %B %P
 *     %O  the common ancestor's version
 *     %A  our version — ALSO the file the result must be written to
 *     %B  their version
 *     %P  the path being merged, used only for messages
 *
 * Exit codes:
 *   0  merged cleanly; %A holds the merged catalogue
 *   1  at least one key conflicts, or a version does not parse; %A holds the
 *      merge with conflict markers, or our version untouched when nothing
 *      could be parsed
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const fs = require('fs')
const { renderJs, sortKeys } = require('../scripts/build-l10n-js.js')

const INDENT = '    '
const ENTRY_INDENT = INDENT.repeat(2)

/**
 * Read one of the three versions git handed us.
 *
 * An empty or absent file is legitimate rather than an error: git passes an
 * empty temp file for the ancestor when both sides ADDED the catalogue, and
 * that reads correctly as "no keys existed before".
 *
 * @param {string} file - path to the temp file git wrote
 * @return {string} the contents, '' when absent
 */
function readSide(file) {
	try {
		return fs.readFileSync(file, 'utf8')
	} catch {
		return ''
	}
}

/**
 * Compare two catalogue values for equality.
 *
 * `===` is not enough and the difference is not cosmetic: a PLURAL entry's
 * value is an ARRAY of forms (`"_{count} working day_::_{count} working days_":
 * ["{count} working day", "{count} working days"]`, four of them in this app).
 * Two arrays holding the same strings are never `===`, so an identity test
 * would report every plural entry as changed on both sides and manufacture a
 * conflict on every single merge — the exact failure this driver exists to end.
 *
 * @param {string|string[]|undefined} a - one value, undefined for "absent"
 * @param {string|string[]|undefined} b - the other value, undefined for "absent"
 * @return {boolean} whether the two are the same translation
 */
function sameValue(a, b) {
	if (a === b) {
		return true
	}
	if (a === undefined || b === undefined) {
		return false
	}
	return JSON.stringify(a) === JSON.stringify(b)
}

/**
 * Three-way merge of a single value.
 *
 * `undefined` stands for "absent", so addition and deletion fall out of the
 * same three comparisons: a side that still equals the ancestor did not touch
 * the value, so the other side's decision wins.
 *
 * @param {string|string[]|undefined} base - the ancestor's value
 * @param {string|string[]|undefined} ours - our value
 * @param {string|string[]|undefined} theirs - their value
 * @return {{ value: (string|string[]|undefined), conflict: boolean }} the
 *   resolved value, and whether
 *   both sides changed it to something different
 */
function mergeValue(base, ours, theirs) {
	if (sameValue(ours, theirs)) {
		return { value: ours, conflict: false }
	}
	if (sameValue(ours, base)) {
		return { value: theirs, conflict: false }
	}
	if (sameValue(theirs, base)) {
		return { value: ours, conflict: false }
	}
	return { value: ours, conflict: true }
}

/**
 * Parse a catalogue in either of the two shapes this repo keeps.
 *
 * `l10n/<locale>.json` is the source a human edits; `l10n/<locale>.js` is the
 * `OC.L10N.register(...)` call `scripts/build-l10n-js.js` generates from it,
 * and it conflicts in exactly the same place, so both are handled here.
 *
 * @param {string} text - the file contents
 * @return {object|null} `{ kind, id, pluralForm, translations, extra }`, or
 *   null when the text is not a catalogue this driver understands
 */
function parseCatalogue(text) {
	const trimmed = text.trim()
	if (trimmed === '') {
		return {
			kind: 'empty',
			id: undefined,
			pluralForm: undefined,
			translations: {},
			extra: {},
		}
	}

	if (trimmed.startsWith('{')) {
		let doc
		try {
			doc = JSON.parse(trimmed)
		} catch {
			return null
		}
		if (doc === null || typeof doc !== 'object' || Array.isArray(doc)) {
			return null
		}
		const extra = {}
		for (const [key, value] of Object.entries(doc)) {
			if (key !== 'translations') {
				extra[key] = value
			}
		}
		return {
			kind: 'json',
			id: undefined,
			pluralForm: undefined,
			translations:
				doc.translations !== null && typeof doc.translations === 'object'
					? doc.translations
					: {},
			extra,
		}
	}

	if (!trimmed.startsWith('OC.L10N.register(')) {
		return null
	}

	// The generated shape is fixed, so the object literal is located by its
	// braces rather than by a regex that would have to model every escape:
	// everything between the FIRST `{` and the LAST `}` is the translations
	// object, and `renderJs` writes both keys and values with JSON.stringify,
	// so that slice is valid JSON.
	const open = trimmed.indexOf('{')
	const close = trimmed.lastIndexOf('}')
	const closeParen = trimmed.lastIndexOf(')')
	if (open === -1 || close === -1 || close < open) {
		return null
	}
	const idMatch = trimmed
		.slice('OC.L10N.register('.length, open)
		.match(/"(?:[^"\\]|\\.)*"/)
	const tail = trimmed.slice(close + 1, closeParen === -1 ? undefined : closeParen)
	const pluralMatch = tail.match(/"(?:[^"\\]|\\.)*"/)
	if (idMatch === null || pluralMatch === null) {
		return null
	}
	let translations
	let id
	let pluralForm
	try {
		translations = JSON.parse(trimmed.slice(open, close + 1))
		id = JSON.parse(idMatch[0])
		pluralForm = JSON.parse(pluralMatch[0])
	} catch {
		return null
	}
	return { kind: 'js', id, pluralForm, translations, extra: {} }
}

/**
 * Three-way merge of the key set itself.
 *
 * @param {object} base - the ancestor's translations
 * @param {object} ours - our translations
 * @param {object} theirs - their translations
 * @return {{ entries: Array, conflicts: Array }} merged entries in canonical
 *   key order (each `{ key, value }`, or `{ key, ours, theirs, conflict }`)
 *   and the subset that conflicts
 */
function mergeTranslations(base, ours, theirs) {
	const keys = sortKeys([
		...new Set([
			...Object.keys(base),
			...Object.keys(ours),
			...Object.keys(theirs),
		]),
	])

	const entries = []
	const conflicts = []
	for (const key of keys) {
		const b = Object.hasOwn(base, key) ? base[key] : undefined
		const o = Object.hasOwn(ours, key) ? ours[key] : undefined
		const t = Object.hasOwn(theirs, key) ? theirs[key] : undefined

		const { value, conflict } = mergeValue(b, o, t)
		if (conflict) {
			const entry = { key, ours: o, theirs: t, conflict: true }
			entries.push(entry)
			conflicts.push(entry)
			continue
		}
		if (value === undefined) {
			// Deleted on the side that moved: the key leaves the catalogue.
			continue
		}
		entries.push({ key, value })
	}
	return { entries, conflicts }
}

/**
 * Render one `"key": value` line the way the surrounding file writes it.
 *
 * In a `.json` catalogue an array-valued plural entry is written multi-line by
 * `JSON.stringify(doc, null, 4)`, so the continuation lines are re-indented to
 * match; in a `.js` catalogue `renderJs` writes every value compactly on one
 * line. Getting this wrong would leave a merged file that differs from a
 * rebuilt one, and the next writer would show it as a spurious diff.
 *
 * @param {string} key - the translation key
 * @param {string|string[]} value - the translation, or its plural forms
 * @param {boolean} compact - true for the `.js` shape (no inner newlines)
 * @param {string} comma - ',' unless this is the last entry
 * @return {string} the rendered line
 */
function renderValueLine(key, value, compact, comma) {
	const rendered = compact
		? JSON.stringify(value)
		: JSON.stringify(value, null, 4)
				.split('\n')
				.map((line, i) => (i === 0 ? line : ENTRY_INDENT + line))
				.join('\n')
	return `${ENTRY_INDENT}${JSON.stringify(key)}: ${rendered}${comma}`
}

/**
 * Render one merged entry, or the marker block when both sides disagree.
 *
 * A side that DELETED the key contributes no line inside its half, which reads
 * correctly: that half of the conflict is "this key is gone".
 *
 * @param {object} entry - an entry from `mergeTranslations`
 * @param {boolean} compact - true for the `.js` shape
 * @param {boolean} last - whether this is the final entry
 * @return {string} the rendered line or marker block
 */
function renderEntry(entry, compact, last) {
	const comma = last ? '' : ','
	if (entry.conflict !== true) {
		return renderValueLine(entry.key, entry.value, compact, comma)
	}
	const lines = ['<<<<<<< ours']
	if (entry.ours !== undefined) {
		lines.push(renderValueLine(entry.key, entry.ours, compact, comma))
	}
	lines.push('=======')
	if (entry.theirs !== undefined) {
		lines.push(renderValueLine(entry.key, entry.theirs, compact, comma))
	}
	lines.push('>>>>>>> theirs')
	return lines.join('\n')
}

/**
 * Turn merged entries back into a plain object, for the conflict-free path.
 *
 * @param {Array} entries - merged entries from `mergeTranslations`
 * @return {object} key -> translation, in the entries' order
 */
function toObject(entries) {
	const out = {}
	for (const entry of entries) {
		out[entry.key] = entry.value
	}
	return out
}

/**
 * Render the merged entries as `l10n/<locale>.json`.
 *
 * With no conflicts this is the same `JSON.stringify(doc, null, 4)` that
 * `tests/l10n/check-l10n.js --write` produces, so a merged catalogue is
 * byte-identical to a rewritten one. Only the conflicted case is hand-rendered,
 * because git's markers are not valid JSON and `JSON.stringify` cannot emit
 * them.
 *
 * @param {Array} entries - merged entries from `mergeTranslations`
 * @param {object} extra - merged top-level fields other than `translations`
 * @param {boolean} hasConflicts - whether any entry carries markers
 * @return {string} the file body, newline-terminated
 */
function renderJson(entries, extra, hasConflicts) {
	if (!hasConflicts) {
		return (
			JSON.stringify({ ...extra, translations: toObject(entries) }, null, 4)
			+ '\n'
		)
	}
	const lines = ['{']
	for (const [key, value] of Object.entries(extra)) {
		lines.push(`${INDENT}${JSON.stringify(key)}: ${JSON.stringify(value)},`)
	}
	lines.push(`${INDENT}"translations": {`)
	for (const [i, entry] of entries.entries()) {
		lines.push(renderEntry(entry, false, i === entries.length - 1))
	}
	lines.push(`${INDENT}}`)
	lines.push('}')
	return lines.join('\n') + '\n'
}

/**
 * Render the merged entries as the generated `l10n/<locale>.js`.
 *
 * With no conflicts this delegates to the very function `npm run l10n:build`
 * uses, so a merged file is byte-identical to a rebuilt one and
 * `npm run check:l10n-js` stays quiet.
 *
 * @param {Array} entries - merged entries from `mergeTranslations`
 * @param {string} id - the app id to register under
 * @param {string} pluralForm - the catalogue's gettext plural rule
 * @param {boolean} hasConflicts - whether any entry carries markers
 * @return {string} the file body, newline-terminated
 */
function renderJsCatalogue(entries, id, pluralForm, hasConflicts) {
	if (!hasConflicts) {
		return renderJs(id, toObject(entries), pluralForm)
	}
	const body = entries
		.map((entry, i) => renderEntry(entry, true, i === entries.length - 1))
		.join('\n')
	return [
		'OC.L10N.register(',
		`${INDENT}${JSON.stringify(id)},`,
		`${INDENT}{`,
		body,
		`${INDENT}},`,
		`${INDENT}${JSON.stringify(pluralForm)}`,
		')',
		'',
	].join('\n')
}

/**
 * Merge three catalogue texts.
 *
 * Exported so the unit tests can exercise the merge itself without a git
 * repository, temp files, or a spawned process.
 *
 * @param {string} baseText - the ancestor's contents
 * @param {string} oursText - our contents
 * @param {string} theirsText - their contents
 * @return {{ text: string, conflicts: Array, error: string|null }} the merged
 *   body, the conflicting keys, and a message when one side is unreadable
 */
function mergeCatalogues(baseText, oursText, theirsText) {
	const base = parseCatalogue(baseText)
	const ours = parseCatalogue(oursText)
	const theirs = parseCatalogue(theirsText)

	if (base === null || ours === null || theirs === null) {
		return {
			text: oursText,
			conflicts: [],
			error: 'one of the three versions is not a readable l10n catalogue',
		}
	}

	const { entries, conflicts } = mergeTranslations(
		base.translations,
		ours.translations,
		theirs.translations,
	)
	const hasConflicts = conflicts.length > 0

	// An empty ancestor carries no shape of its own, so the kind comes from
	// whichever real version is at hand.
	const kind =
		[ours.kind, theirs.kind, base.kind].find((k) => k !== 'empty') || 'json'

	if (kind === 'js') {
		const id = mergeValue(base.id, ours.id, theirs.id)
		const plural = mergeValue(
			base.pluralForm,
			ours.pluralForm,
			theirs.pluralForm,
		)
		if (id.conflict || plural.conflict) {
			return {
				text: oursText,
				conflicts,
				error: 'the app id or plural form differs on both sides; resolve it by hand',
			}
		}
		return {
			text: renderJsCatalogue(entries, id.value, plural.value, hasConflicts),
			conflicts,
			error: null,
		}
	}

	const extra = {}
	const extraKeys = [
		...new Set([
			...Object.keys(ours.extra),
			...Object.keys(base.extra),
			...Object.keys(theirs.extra),
		]),
	]
	for (const key of extraKeys) {
		const merged = mergeValue(
			base.extra[key],
			ours.extra[key],
			theirs.extra[key],
		)
		if (merged.conflict) {
			return {
				text: oursText,
				conflicts,
				error: `top-level "${key}" differs on both sides; resolve it by hand`,
			}
		}
		if (merged.value !== undefined) {
			extra[key] = merged.value
		}
	}

	return {
		text: renderJson(entries, extra, hasConflicts),
		conflicts,
		error: null,
	}
}

/**
 * Entry point: read git's three temp files, write the merge into %A.
 *
 * @return {void}
 */
function main() {
	const [basePath, oursPath, theirsPath, mergedPath] = process.argv.slice(2)
	if (!basePath || !oursPath || !theirsPath) {
		console.error('merge-l10n: usage: node tools/merge-l10n.js %O %A %B %P')
		process.exit(1)
	}
	const label = mergedPath || oursPath

	const result = mergeCatalogues(
		readSide(basePath),
		readSide(oursPath),
		readSide(theirsPath),
	)

	fs.writeFileSync(oursPath, result.text)

	if (result.error !== null) {
		console.error(`merge-l10n: ${label}: ${result.error}`)
		process.exit(1)
	}
	if (result.conflicts.length > 0) {
		console.error(
			`merge-l10n: ${label}: ${result.conflicts.length} key(s) translated `
				+ 'differently on both sides; conflict markers written:',
		)
		for (const entry of result.conflicts.slice(0, 20)) {
			console.error(`  • ${JSON.stringify(entry.key)}`)
		}
		if (result.conflicts.length > 20) {
			console.error(`  … +${result.conflicts.length - 20} more`)
		}
		process.exit(1)
	}
	console.error(`merge-l10n: ${label}: merged cleanly, no key conflicts`)
	process.exit(0)
}

if (require.main === module) {
	main()
}

module.exports = {
	mergeCatalogues,
	mergeTranslations,
	mergeValue,
	parseCatalogue,
	sameValue,
	sortKeys,
}
