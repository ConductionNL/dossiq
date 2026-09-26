// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The debt ratchet has to actually fail.
 *
 * `scripts/debt-ratchet.js` counts suppressed static-analysis findings and
 * fails when the count grows. On this repo it currently prints ten numbers and
 * exits 0, and so would a script that counted nothing at all. That is the
 * shape of every dead gate this fleet has shipped: a check that cannot fail
 * reports the same green as one that passed.
 *
 * So these tests drive it against a throwaway tree and watch it go red, once
 * per direction:
 *
 *   - a suppression added   -> exit 1, and the message names the metric
 *   - a suppression removed -> exit 1, and the baseline is rewritten lower
 *   - nothing changed       -> exit 0
 *
 * The tree is built here rather than committed as a fixture, because a
 * committed fixture full of @psalm-suppress tags would be counted by the real
 * census and the ratchet would then be measuring itself.
 */
import { execFileSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'

const SCRIPT = path.resolve(__dirname, '../../scripts/debt-ratchet.js')

let root = null

/**
 * Build a minimal tree the census understands.
 *
 * @param {object} options - what to plant
 * @param {number} options.psalmSuppress - inline @psalm-suppress tags in lib/
 * @param {number} options.blanketHandlers - blanket issue handlers in psalm.xml
 * @return {string} the tree root
 */
function makeTree({ psalmSuppress = 2, blanketHandlers = 1 } = {}) {
	const dir = mkdtempSync(path.join(tmpdir(), 'debt-ratchet-'))
	mkdirSync(path.join(dir, 'lib'), { recursive: true })
	mkdirSync(path.join(dir, 'src'), { recursive: true })
	mkdirSync(path.join(dir, 'tests'), { recursive: true })
	mkdirSync(path.join(dir, 'scripts'), { recursive: true })

	const tags = Array.from(
		{ length: psalmSuppress },
		(_, i) => ` * @psalm-suppress MixedAssignment reason ${i}`,
	).join('\n')
	writeFileSync(
		path.join(dir, 'lib', 'Thing.php'),
		`<?php\n/**\n${tags}\n */\nclass Thing {}\n`,
	)

	const handlers = Array.from(
		{ length: blanketHandlers },
		(_, i) => `        <Handler${i} errorLevel="suppress"/>`,
	).join('\n')
	writeFileSync(
		path.join(dir, 'psalm.xml'),
		`<?xml version="1.0"?>\n<psalm>\n    <issueHandlers>\n${handlers}\n    </issueHandlers>\n</psalm>\n`,
	)

	writeFileSync(path.join(dir, 'phpstan.neon'), 'parameters:\n    ignoreErrors:\n')
	writeFileSync(path.join(dir, 'eslint-suppressions.json'), '{}\n')
	return dir
}

/**
 * Run the ratchet against a tree.
 *
 * @param {string} dir - tree root
 * @param {string[]} args - extra arguments
 * @return {{status: number, out: string}} exit code and combined output
 */
function run(dir, args = []) {
	try {
		const out = execFileSync('node', [SCRIPT, ...args], {
			env: { ...process.env, DEBT_RATCHET_ROOT: dir },
			encoding: 'utf8',
			stdio: ['ignore', 'pipe', 'pipe'],
		})
		return { status: 0, out }
	} catch (error) {
		return {
			status: error.status,
			out: `${error.stdout || ''}${error.stderr || ''}`,
		}
	}
}

afterEach(() => {
	if (root !== null) {
		rmSync(root, { recursive: true, force: true })
		root = null
	}
})

describe('debt ratchet', () => {
	it('passes when nothing moved', () => {
		root = makeTree()
		expect(run(root, ['--update']).status).toBe(0)
		expect(run(root).status).toBe(0)
	})

	it('fails and names the analyser when a suppression is added', () => {
		root = makeTree({ psalmSuppress: 2 })
		run(root, ['--update'])

		writeFileSync(
			path.join(root, 'lib', 'Other.php'),
			'<?php\n/**\n * @psalm-suppress MixedAssignment one more\n */\nclass Other {}\n',
		)

		const result = run(root)
		expect(result.status).toBe(1)
		expect(result.out).toContain('psalm.inlineSuppressions: 2 to 3')
		expect(result.out).toContain('1 more hidden')
	})

	it('fails and names the analyser when a blanket handler is added', () => {
		root = makeTree({ blanketHandlers: 1 })
		run(root, ['--update'])

		const xml = readFileSync(path.join(root, 'psalm.xml'), 'utf8')
		writeFileSync(
			path.join(root, 'psalm.xml'),
			xml.replace(
				'    </issueHandlers>',
				'        <Extra errorLevel="suppress"/>\n    </issueHandlers>',
			),
		)

		const result = run(root)
		expect(result.status).toBe(1)
		expect(result.out).toContain('psalm.blanketHandlers: 1 to 2')
	})

	it('tightens the baseline when a suppression goes away', () => {
		root = makeTree({ psalmSuppress: 3 })
		run(root, ['--update'])
		expect(
			JSON.parse(readFileSync(path.join(root, '.debt-baseline.json'), 'utf8'))[
				'psalm.inlineSuppressions'
			],
		).toBe(3)

		writeFileSync(
			path.join(root, 'lib', 'Thing.php'),
			'<?php\n/**\n * @psalm-suppress MixedAssignment reason 0\n */\nclass Thing {}\n',
		)

		const result = run(root)
		expect(result.status).toBe(1)
		expect(result.out).toContain('2 fewer hidden')
		expect(
			JSON.parse(readFileSync(path.join(root, '.debt-baseline.json'), 'utf8'))[
				'psalm.inlineSuppressions'
			],
		).toBe(1)
	})

	it('refuses to run with no baseline committed', () => {
		root = makeTree()
		const result = run(root)
		expect(result.status).toBe(1)
		expect(result.out).toContain('No baseline')
	})
})
