#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// debt-ratchet.js — a RATCHET on suppressed static-analysis findings.
//
// WHY THIS EXISTS
//
//   Measured on `development` on 2026-09-12, every analyser this app runs
//   reports ZERO findings: PHPCS 0, PHPMD 0, Psalm 0, PHPStan 0, and eslint
//   exits clean. That number is true and it is also misleading. It is zero
//   because 1,100+ findings are suppressed, not because they were fixed:
//
//     Psalm      31 blanket issue handlers in psalm.xml, each switching off a
//                whole issue class for the whole app, plus 294 inline
//                @psalm-suppress tags in lib/.
//     PHPMD     278 @SuppressWarnings annotations in lib/.
//     eslint    388 violations parked in eslint-suppressions.json, across 118
//                files, plus 16 inline eslint-disable comments.
//     PHPStan    11 ignoreErrors patterns, plus 7 inline @phpstan-ignore tags.
//     PHPCS       8 inline phpcs:ignore comments.
//
//   Because the findings are already gated at zero, the ONLY way to add
//   static-analysis debt to this app is to add a suppression. Today that costs
//   nothing and nobody sees it: the analyser goes quiet, CI stays green, and
//   the number a reviewer looks at does not move. This script makes that move
//   visible and makes it fail.
//
//   It is a RATCHET, not a gate. It records how many findings are currently
//   hidden and fails when that number GROWS. Burning the debt down stays an
//   ordinary pull request, and the moment a number drops the script rewrites
//   the baseline so the slack cannot be spent later.
//
// WHAT IT COUNTS, AND WHY EACH ONE IS A HIDING PLACE
//
//   psalm.blanketHandlers        <X errorLevel="suppress"/> in psalm.xml. Kills
//                                one issue class app-wide. The widest hiding
//                                place in the repo by a distance.
//   psalm.referencedClasses      <referencedClass name="..."/> under
//                                UndefinedClass. Narrow and mostly legitimate:
//                                these name cross-app classes that really are
//                                absent from this app's analysis path. Counted
//                                anyway, because a wrong name here makes the
//                                integration inert rather than red.
//   psalm.inlineSuppressions     @psalm-suppress in lib/.
//   phpstan.ignorePatterns       ignoreErrors entries in phpstan.neon.
//   phpstan.inlineIgnores        @phpstan-ignore in lib/.
//   phpcs.inlineIgnores          phpcs:ignore and phpcs:disable in lib/.
//   phpmd.suppressWarnings       @SuppressWarnings in lib/.
//   eslint.suppressedViolations  the summed counts in eslint-suppressions.json.
//   eslint.inlineDisables        eslint-disable comments in the lint scope.
//   phpunit.skips                markTestSkipped and markTestIncomplete in
//                                tests/. A skipped test hides a finding the
//                                same way a suppression does.
//
//   Playwright skips are deliberately NOT counted here. The shared quality
//   pipeline already runs a skip-discipline gate over tests/e2e, and two
//   instruments counting one thing disagree sooner or later.
//
// Usage:
//   node scripts/debt-ratchet.js            (npm run check:debt-ratchet)
//   node scripts/debt-ratchet.js --update   rewrite the baseline
//   node scripts/debt-ratchet.js --list     print where each suppression sits
//
// Exit codes:
//   0 — every count matches its baseline
//   1 — a count grew, or a count shrank and the baseline was rewritten for you
//       to commit, or the baseline file is missing

'use strict'

const fs = require('fs')
const path = require('path')

// DEBT_RATCHET_ROOT exists so tests/vitest/debtRatchet.spec.js can point the
// whole census at a fixture tree. A ratchet nobody has watched fail is a
// ratchet that might not fail, which is the failure mode this repo has paid
// for before. Nothing in CI sets it.
const REPO_ROOT = process.env.DEBT_RATCHET_ROOT
	? path.resolve(process.env.DEBT_RATCHET_ROOT)
	: path.resolve(__dirname, '..')
const BASELINE = path.join(REPO_ROOT, '.debt-baseline.json')

/**
 * Every file under a directory whose name ends in one of the given suffixes.
 *
 * @param {string} dir - directory to walk, absolute
 * @param {string[]} suffixes - file name endings to keep
 * @return {string[]} absolute paths, empty when the directory is absent
 */
function walk(dir, suffixes) {
	if (fs.existsSync(dir) === false) {
		return []
	}
	return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			return walk(full, suffixes)
		}
		return suffixes.some((s) => entry.name.endsWith(s)) ? [full] : []
	})
}

/**
 * Count regex matches across a set of files, keeping one hit per site.
 *
 * @param {string[]} files - absolute paths to read
 * @param {RegExp} pattern - global regex applied per file
 * @return {Array<{file: string, line: number, text: string}>} the hits
 */
function hits(files, pattern) {
	const found = []
	for (const file of files) {
		const lines = fs.readFileSync(file, 'utf8').split('\n')
		lines.forEach((text, index) => {
			const matches = text.match(pattern)
			if (matches !== null) {
				for (let i = 0; i < matches.length; i++) {
					found.push({
						file: path.relative(REPO_ROOT, file),
						line: index + 1,
						text: text.trim(),
					})
				}
			}
		})
	}
	return found
}

/**
 * Read a repo file, or an empty string when it is absent.
 *
 * @param {string} rel - path relative to the repo root
 * @return {string} file contents
 */
function readOrEmpty(rel) {
	const full = path.join(REPO_ROOT, rel)
	return fs.existsSync(full) ? fs.readFileSync(full, 'utf8') : ''
}

/**
 * Entries under phpstan.neon's ignoreErrors key.
 *
 * Counts list items at the key's own indentation, so a `message:` or `path:`
 * line inside one entry is never counted as a second entry.
 *
 * @return {Array<{file: string, line: number, text: string}>} the hits
 */
function phpstanIgnores() {
	const src = readOrEmpty('phpstan.neon')
	const lines = src.split('\n')
	const start = lines.findIndex((l) => /^\s*ignoreErrors:\s*$/.test(l))
	if (start === -1) {
		return []
	}
	const keyIndent = lines[start].match(/^\s*/)[0].length
	const found = []
	for (let i = start + 1; i < lines.length; i++) {
		const line = lines[i]
		if (line.trim() === '' || line.trim().startsWith('#')) {
			continue
		}
		const indent = line.match(/^\s*/)[0].length
		if (indent <= keyIndent) {
			break
		}
		// A list item at the first level below the key is one ignore.
		if (/^\s*-\s/.test(line) === false && /^\s*-$/.test(line) === false) {
			continue
		}
		const itemIndent = indent
		if (found.length === 0) {
			found.push({
				file: 'phpstan.neon',
				line: i + 1,
				text: line.trim(),
				indent: itemIndent,
			})
			continue
		}
		if (itemIndent === found[0].indent) {
			found.push({
				file: 'phpstan.neon',
				line: i + 1,
				text: line.trim(),
				indent: itemIndent,
			})
		}
	}
	return found
}

/**
 * Violations parked in the eslint bulk suppressions file.
 *
 * @return {number} summed violation count across every file and rule
 */
function eslintSuppressed() {
	const raw = readOrEmpty('eslint-suppressions.json')
	if (raw === '') {
		return 0
	}
	const parsed = JSON.parse(raw)
	let total = 0
	for (const rules of Object.values(parsed)) {
		for (const entry of Object.values(rules)) {
			total += typeof entry === 'number' ? entry : entry.count || 0
		}
	}
	return total
}

/**
 * Measure every hiding place once.
 *
 * @return {object} counts keyed by metric, and the hits behind each
 */
function measure() {
	const libPhp = walk(path.join(REPO_ROOT, 'lib'), ['.php'])
	const testPhp = walk(path.join(REPO_ROOT, 'tests'), ['.php'])
	const lintScope = [
		...walk(path.join(REPO_ROOT, 'src'), ['.js', '.ts', '.vue', '.mjs', '.cjs']),
		...walk(path.join(REPO_ROOT, 'scripts'), ['.js', '.ts', '.mjs', '.cjs']),
	]

	const psalmXml = readOrEmpty('psalm.xml').split('\n')
	const blanket = []
	psalmXml.forEach((text, index) => {
		if (
			/^\s*<[A-Za-z][A-Za-z0-9]*\s+errorLevel="suppress"\s*\/>\s*$/.test(text)
		) {
			blanket.push({ file: 'psalm.xml', line: index + 1, text: text.trim() })
		}
	})
	const referenced = []
	psalmXml.forEach((text, index) => {
		if (/<referencedClass\s/.test(text)) {
			referenced.push({
				file: 'psalm.xml',
				line: index + 1,
				text: text.trim(),
			})
		}
	})

	return {
		'psalm.blanketHandlers': blanket,
		'psalm.referencedClasses': referenced,
		'psalm.inlineSuppressions': hits(libPhp, /@psalm-suppress\b/g),
		'phpstan.ignorePatterns': phpstanIgnores(),
		'phpstan.inlineIgnores': hits(libPhp, /@phpstan-ignore\b/g),
		'phpcs.inlineIgnores': hits(libPhp, /phpcs:(?:ignore|disable)\b/g),
		'phpmd.suppressWarnings': hits(libPhp, /@SuppressWarnings\s*\(/g),
		// The directive has to OPEN the comment. A tag anywhere in the line
		// also matches prose about eslint, including the header of this file,
		// which is how this metric first read 19 instead of 16.
		'eslint.inlineDisables': hits(
			lintScope,
			/(?:\/\/|\/\*|<!--)\s*eslint-disable(?:-next-line|-line)?\b/g,
		),
		'phpunit.skips': hits(testPhp, /markTest(?:Skipped|Incomplete)\s*\(/g),
	}
}

/**
 * Print a one line summary per metric.
 *
 * @param {object} counts - measured counts keyed by metric
 * @param {object} baseline - committed counts keyed by metric
 * @return {void}
 */
function report(counts, baseline) {
	const width = Math.max(...Object.keys(counts).map((k) => k.length))
	for (const [metric, count] of Object.entries(counts)) {
		const was = baseline === null ? null : baseline[metric]
		const mark =
			was === null || was === undefined || was === count
				? ' '
				: count > was
					? '+'
					: '-'
		const tail = was === null || was === undefined ? '' : `  baseline ${was}`
		console.log(
			`${mark} ${metric.padEnd(width)}  ${String(count).padStart(5)}${tail}`,
		)
	}
}

/**
 * Run the ratchet.
 *
 * @return {void}
 */
function main() {
	const args = process.argv.slice(2)
	const update = args.includes('--update')
	const list = args.includes('--list')

	const evidence = measure()
	const counts = { 'eslint.suppressedViolations': eslintSuppressed() }
	for (const [metric, found] of Object.entries(evidence)) {
		counts[metric] = found.length
	}
	// Stable ordering, so a diff of the baseline reads as a change in numbers.
	const ordered = {}
	for (const key of Object.keys(counts).sort()) {
		ordered[key] = counts[key]
	}

	if (list) {
		for (const [metric, found] of Object.entries(evidence)) {
			console.log(`\n${metric} (${found.length})`)
			for (const hit of found) {
				console.log(`  ${hit.file}:${hit.line}  ${hit.text.slice(0, 110)}`)
			}
		}
		console.log(
			'\neslint.suppressedViolations lives in eslint-suppressions.json',
		)
	}

	const total = Object.values(ordered).reduce((a, b) => a + b, 0)

	if (update) {
		fs.writeFileSync(BASELINE, JSON.stringify(ordered, null, 2) + '\n')
		report(ordered, null)
		console.log(
			`\nbaseline written: ${total} suppressed finding(s) across ${Object.keys(ordered).length} metrics`,
		)
		return
	}

	if (fs.existsSync(BASELINE) === false) {
		console.error(
			'No baseline. Run `npm run check:debt-ratchet -- --update` and commit .debt-baseline.json.',
		)
		process.exit(1)
	}
	const baseline = JSON.parse(fs.readFileSync(BASELINE, 'utf8'))

	report(ordered, baseline)

	const grew = []
	const shrank = []
	for (const [metric, count] of Object.entries(ordered)) {
		const was = baseline[metric]
		if (was === undefined) {
			grew.push({ metric, was: 0, count, delta: count })
			continue
		}
		if (count > was) {
			grew.push({ metric, was, count, delta: count - was })
		}
		if (count < was) {
			shrank.push({ metric, was, count, delta: was - count })
		}
	}

	if (grew.length > 0) {
		console.error('')
		console.error(
			'Static analysis debt went up. Every analyser still reports zero because',
		)
		console.error('these findings are hidden, not fixed.')
		console.error('')
		for (const g of grew) {
			console.error(
				`  ${g.metric}: ${g.was} to ${g.count}, ${g.delta} more hidden`,
			)
		}
		console.error('')
		console.error(
			'Fix the code the analyser is complaining about, or say in the pull request',
		)
		console.error('why the finding is wrong. See where each one sits with:')
		console.error('  node scripts/debt-ratchet.js --list')
		console.error('')
		console.error(
			'Raising the baseline is a deliberate act. It needs a reviewer who agrees.',
		)
		process.exit(1)
	}

	if (shrank.length > 0) {
		fs.writeFileSync(BASELINE, JSON.stringify(ordered, null, 2) + '\n')
		console.log('')
		console.log(
			'Debt went down. The baseline is tightened and waiting in your working tree.',
		)
		console.log('')
		for (const s of shrank) {
			console.log(
				`  ${s.metric}: ${s.was} to ${s.count}, ${s.delta} fewer hidden`,
			)
		}
		console.log('')
		console.log(
			'Commit .debt-baseline.json so nobody spends the slack you just made:',
		)
		console.log(
			'  git add .debt-baseline.json && git commit -m "chore: tighten the debt ratchet"',
		)
		process.exit(1)
	}

	console.log(`\n${total} suppressed finding(s), holding at the baseline.`)
}

main()
