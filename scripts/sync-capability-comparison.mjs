#!/usr/bin/env node

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Re-issue `openspec/parity/capabilities.json` from the parity corpus.
 *
 * That path is the canonical home every product's capability matrix uses (the
 * hydra `parity-verify` skill validates it there), and the Features & roadmap
 * page imports it from there directly. There is no copy under `src/data`: two
 * copies of one matrix is how the 2026-09-10 drift below happened.
 *
 * WHY THIS SCRIPT EXISTS
 * ----------------------
 * The corpus is the record. It lives in `ConductionNL/market-intelligence` at
 * `procest/_ledger/parity-ledger.html`, and `procest/_round4/tools/corpus-rows.py`
 * writes the machine-readable copy this script reads. The rule has always been
 * that this file is a COPY of that matrix and moves in the same change.
 *
 * On 2026-09-10 it stopped being one. dossiq#2314 added 104 rows here that exist
 * in no corpus artefact, under ids the corpus held for other questions, rating
 * every competitor `unknown`. 42 ids collided. Five discovery lanes then read
 * this file as if it were the ledger and cited 39 of those ids as existing rows,
 * and eleven candidates were withdrawn against rows that did not exist. The
 * reconciliation is `procest/_round4/compare/dossiq-matrix-drift.md`, the
 * decision is D1, and this script plus the test beside it is the third thing
 * that decision asked for: make the copy rule mechanical.
 *
 * WHAT IT WRITES
 * --------------
 *   openspec/parity/capabilities.json            the matrix, which the page imports
 *   src/data/capabilityComparison.corpus-ids.json the id list the test pins
 *
 * WHAT IT KEEPS FROM THE CURRENT FILE
 * -----------------------------------
 * Ids, capability texts and every rating come from the corpus and are never
 * carried over. Three things are:
 *
 *   `name_nl`    matched on the ENGLISH text, not on the id, because 91 ids
 *                moved in the renumbering and the text did not. A row whose
 *                text has no Dutch falls through to English, which `labelFor`
 *                already handles by design.
 *   `_rerated`   the log of every move in our own column, for rows that are
 *                still rows. An entry for a row that became a pending proposal
 *                moves with it, into that proposal's `dossiqNote`.
 *   the metadata `comparedOn`, `systems` and the areas' Dutch names.
 *
 * USAGE
 * -----
 *   node scripts/sync-capability-comparison.mjs --corpus ../market-intelligence/procest/_round4/tools/corpus-rows.json
 *   node scripts/sync-capability-comparison.mjs --corpus <path> --check
 *
 * `--check` writes nothing and exits 1 when the committed files differ from what
 * the corpus would produce. It needs a market-intelligence checkout, so it is a
 * local and nightly check rather than a per-PR one. The per-PR check is
 * `tests/vitest/capabilityComparison.spec.js`, which needs only this repo.
 */

import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const DATA = join(HERE, '..', 'openspec', 'parity', 'capabilities.json')
const IDS = join(HERE, '..', 'src', 'data', 'capabilityComparison.corpus-ids.json')

/** What the file says about itself, for anyone who opens it rather than the page. */
const COMMENT = [
	"dossiq's capability matrix, at the path every product's matrix uses, and the only copy:",
	'the Features & roadmap page imports it from here. Ids, capability texts and ratings are',
	'RE-ISSUED FROM THE CORPUS by scripts/sync-capability-comparison.mjs. Do not edit those by',
	'hand: the corpus is ConductionNL/market-intelligence, procest/_ledger/parity-ledger.html,',
	'and every id, capability text and rating here comes from it. What is authored here and',
	'carried through a re-issue: per row `provider`, `providerHow`, `feature`,',
	'`featureConfidence`, `built` (the machine-readable half of a closure: prose saying closed',
	'is not a closed row) and `evidence` (what was seen, per system), and per system',
	'`evidenceGrade` and `unknownReason`. A hand edit to the ratings is what happened on 2026-09-10,',
	'and it put 104 rows on this page that no corpus artefact held, under ids the corpus used',
	'for other questions. tests/vitest/capabilityComparison.spec.js now fails when the id set',
	'here differs from capabilityComparison.corpus-ids.json.',
	'',
	'`capabilities` are the 225 ROWS: every column has been read against each of them, and',
	'`comparedOn` is the date the first four were read. It moves only when all four are read',
	'again. A column added later carries its own `readOn` and `columnAddedOn`, because moving',
	'`comparedOn` to cover it would make the reading-date sentence false for the four it',
	'already covers. A row added by a later round carries its own `addedOn` and rates every',
	"competitor `unknown`, because a guess in someone else's column is worse than an empty",
	'cell, and `rowsAddedOn` is the most recent of those dates.',
	'',
	'`pending` are the 146 PROPOSALS: questions raised against one product, not yet read',
	'against the rest. Every competitor cell on them is `unknown` and our own is rated,',
	'because we can always read our own code. They are not rows and they are not counted in',
	'any tally. A proposal becomes a row when every column has been read for it, and its id is',
	'assigned at that moment, centrally, in the corpus.',
	'',
	'Our OWN column is corrected between rounds, because a rating that says we lack something',
	'we shipped is the one error on this page a reader cannot check for themselves. The',
	"competitor columns are NOT corrected that way: we would be re-reading someone else's",
	'product without saying so, and the honest fix for those is a new round with a new',
	'comparedOn. Every correction and every added row is in `_rerated` with its date and its',
	'reason, and `reratedOn` carries the latest.',
]
	.join(' ')
	.replace(/ {2,}/g, ' ')

/** Ratings the page understands. A corpus `unread` becomes `unknown` here. */
const RATINGS = ['yes', 'partial', 'no']
/** The five columns the page publishes, in the order it renders them. */
const COLUMNS = ['dossiq', 'opencase', 'gzac', 'zaaksysteem', 'zac']

// Authored per row and absent from the corpus: who provides the capability,
// how that was derived, which feature bundles it, and how sure that is; then
// `built`, the machine-readable half of a closure (prose saying a row was
// closed is not counted by anything, only this field is), and `evidence`, what
// was seen per system. The hydra parity-verify skill reads the last two.
const AUTHORED_FIELDS = [
	'provider',
	'providerHow',
	'feature',
	'featureConfidence',
	'built',
	'evidence',
]

/**
 * Read `--flag value` off the argv, or return the fallback.
 *
 * @param {string} flag The flag to read, e.g. `--corpus`.
 * @param {string|null} fallback What to return when the flag is absent.
 * @return {string|null} The value.
 */
function arg(flag, fallback = null) {
	const i = process.argv.indexOf(flag)
	return i === -1 || i === process.argv.length - 1 ? fallback : process.argv[i + 1]
}

/**
 * Fail with a message a reader can act on.
 *
 * @param {string} message What went wrong.
 * @return {never}
 */
function die(message) {
	console.error(`sync-capability-comparison: ${message}`)
	process.exit(1)
}

/**
 * Build the data file from the corpus and the current file's translations.
 *
 * @param {object} corpus Parsed `corpus-rows.json`.
 * @param {object} current The file as it stands, for `name_nl` and metadata.
 * @param {string} on The date to log a moved rating under, ISO 8601.
 * @return {{data: object, ids: object, report: object}} What to write, and what moved.
 */
export function build(corpus, current, on) {
	// BOTH lists, not just the rows. A row that became a proposal keeps its
	// Dutch, and reading only `capabilities` here dropped the Dutch of all 104
	// on the second run: the first run had already moved them into `pending`.
	// Caught by running the script twice and diffing, which is the only way an
	// error like this one shows up at all.
	const dutch = new Map()
	for (const row of [
		...(current.capabilities ?? []),
		...(current.pending ?? []),
	]) {
		if (row.name_nl && row.name_nl.trim() !== '') {
			dutch.set(row.name, row.name_nl)
		}
	}

	// The authored fields, carried by id from BOTH lists for the same reason
	// the Dutch above is: a row that became a proposal keeps them. These are
	// not in the corpus and cannot be regenerated, so dropping them here would
	// silently discard 371 provider values and 329 feature mappings on the
	// next re-issue, with the file still looking well formed.
	const authored = new Map()
	for (const row of [
		...(current.capabilities ?? []),
		...(current.pending ?? []),
	]) {
		const held = {}
		for (const field of AUTHORED_FIELDS) {
			if (row[field] !== undefined) {
				held[field] = row[field]
			}
		}
		if (Object.keys(held).length > 0) {
			authored.set(row.id, held)
		}
	}

	const areaKey = new Map((current.areas ?? []).map((a) => [a.name, a.key]))
	const areas = corpus.areas.map((a) => {
		const key = areaKey.get(a.name)
		if (!key) {
			die(`area ${a.n} "${a.name}" has no key in the current file`)
		}
		const held = (current.areas ?? []).find((x) => x.key === key)
		return { key, name: a.name, name_nl: held?.name_nl ?? a.name }
	})
	const keyForArea = new Map(
		corpus.areas.map((a) => [a.name, areaKey.get(a.name)]),
	)

	const previous = new Map((current.capabilities ?? []).map((c) => [c.id, c]))
	const moved = []
	const capabilities = corpus.rows.map((row) => {
		const out = { id: row.id, area: keyForArea.get(row.area), name: row.cap }
		const nl = dutch.get(row.cap)
		if (nl) {
			out.name_nl = nl
		}
		for (const column of COLUMNS) {
			const value = row[column]
			if (![...RATINGS, 'unknown'].includes(value)) {
				die(`row ${row.id} column ${column} is "${value}"`)
			}
			out[column] = value
		}
		Object.assign(out, authored.get(row.id) ?? {})
		const held = previous.get(row.id)
		if (held && held.addedOn) {
			out.addedOn = held.addedOn
		}
		if (held && held.dossiq !== row.dossiq) {
			moved.push({
				id: row.id,
				on,
				from: held.dossiq,
				to: row.dossiq,
				cause: 'corrected',
				// The corpus's own evidence for the new rating. A correction
				// with no reason is a number somebody changed.
				reason: row.dossiqNote,
			})
		}
		return out
	})

	const pending = corpus.pending.map((row) => {
		const out = {
			id: row.id,
			area: keyForArea.get(row.area),
			name: row.cap,
			status: 'pending',
			source: row.source,
			dossiq: row.dossiq,
			dossiqNote: row.dossiqNote,
		}
		const nl = dutch.get(row.cap)
		if (nl) {
			out.name_nl = nl
		}
		// Every competitor is unread in the corpus, which is `unknown` here:
		// the page's word for a cell nobody has filled. It is never `no`.
		for (const column of COLUMNS.filter((c) => c !== 'dossiq')) {
			if (row[column] !== 'unread') {
				die(
					`pending ${row.id} column ${column} is "${row[column]}", expected unread`,
				)
			}
			out[column] = 'unknown'
		}
		Object.assign(out, authored.get(row.id) ?? {})
		return out
	})

	const rowIds = new Set(capabilities.map((c) => c.id))
	// Entries for rows that are still rows, plus one per rating the corpus moved.
	// An entry for a row that became a pending proposal leaves with it: the
	// proposal carries the same sentence as its `dossiqNote`.
	const rerated = [
		...(current._rerated ?? []).filter((entry) => rowIds.has(entry.id)),
		...moved,
	]
	for (const entry of moved) {
		if (!entry.reason || entry.reason.length < 21) {
			die(
				`row ${entry.id} moved ${entry.from} to ${entry.to} with no evidence in the corpus`,
			)
		}
	}

	const data = {
		_comment: COMMENT,
		corpus: {
			repo: 'ConductionNL/market-intelligence',
			file: 'procest/_round4/tools/corpus-rows.json',
			rows: capabilities.length,
			pending: pending.length,
		},
		comparedOn: current.comparedOn,
		rowsAddedOn: capabilities
			.filter((c) => c.addedOn)
			.map((c) => c.addedOn)
			.sort()
			.at(-1),
		reratedOn: rerated
			.map((e) => e.on)
			.sort()
			.at(-1),
		dossiqRevision: current.dossiqRevision,
		_rerated: rerated,
		systems: current.systems,
		// Authored dictionaries, not derivable from the corpus.
		...(current.providers ? { providers: current.providers } : {}),
		...(current.features ? { features: current.features } : {}),
		areas,
		capabilities,
		pending,
	}

	const ids = {
		_comment:
			'Generated by scripts/sync-capability-comparison.mjs from the parity '
			+ 'corpus. tests/vitest/capabilityComparison.spec.js fails when '
			+ 'openspec/parity/capabilities.json holds a different set. Do not edit either '
			+ 'file by hand: change the corpus and re-run the script.',
		rows: capabilities.map((c) => c.id),
		pending: pending.map((c) => c.id),
	}

	return {
		data,
		ids,
		report: { moved, dutchless: pending.filter((p) => !p.name_nl).length },
	}
}

/**
 * Read the corpus, re-issue both files, or with `--check` compare them.
 *
 * @return {void}
 */
function main() {
	const corpusPath = arg('--corpus')
	if (!corpusPath) {
		die(
			'pass --corpus <path to market-intelligence/procest/_round4/tools/corpus-rows.json>',
		)
	}

	let corpus
	try {
		corpus = JSON.parse(readFileSync(corpusPath, 'utf8'))
	} catch (error) {
		die(`cannot read the corpus at ${corpusPath}: ${error.message}`)
	}
	const on = arg('--on', new Date().toISOString().slice(0, 10))
	if (!/^\d{4}-\d{2}-\d{2}$/.test(on)) {
		die(`--on must be an ISO date, got "${on}"`)
	}
	const current = JSON.parse(readFileSync(DATA, 'utf8'))
	const { data, ids, report } = build(corpus, current, on)

	const dataText = JSON.stringify(data, null, '\t') + '\n'
	const idsText = JSON.stringify(ids, null, '\t') + '\n'

	if (process.argv.includes('--check')) {
		const drift = []
		if (readFileSync(DATA, 'utf8') !== dataText) {
			drift.push('openspec/parity/capabilities.json')
		}
		if (readFileSync(IDS, 'utf8') !== idsText) {
			drift.push('src/data/capabilityComparison.corpus-ids.json')
		}
		if (drift.length) {
			console.error(`drifted from the corpus: ${drift.join(', ')}`)
			console.error('run this script without --check to re-issue them')
			process.exit(1)
		}
		console.log(
			`in step with the corpus: ${data.capabilities.length} rows, ${data.pending.length} pending`,
		)
		process.exit(0)
	}

	writeFileSync(DATA, dataText)
	writeFileSync(IDS, idsText)
	console.log(
		`re-issued ${data.capabilities.length} rows and ${data.pending.length} pending`,
	)
	for (const move of report.moved) {
		console.log(`  our column moved: ${move.id} ${move.from} -> ${move.to}`)
	}
	if (report.dutchless) {
		console.log(
			`  ${report.dutchless} pending rows have no Dutch text and fall back to English`,
		)
	}
}

// Run only as a script. The guard test imports `build` to prove the authored
// fields survive a re-issue, and importing must not read argv or write files.
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
	main()
}
