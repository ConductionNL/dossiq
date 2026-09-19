/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A navigation option Playwright refuses throws before the test body runs.
 *
 * 🔴 WHY THIS IS A STATIC CHECK AND NOT A PLAYWRIGHT RUN. `PAGE_LOAD` is the
 * whole options object, `{ timeout: 45_000 }`. Nineteen call sites passed it
 * as the VALUE of `waitUntil`, so every one of them threw
 * `waitUntil: expected one of (load|domcontentloaded|networkidle|commit)`
 * before touching the app. Five spec files could not run anywhere, on CI
 * included, and `widget-roles-declared` lost all four of its scenarios: nobody
 * had ever seen them pass or fail.
 *
 * That is exactly the failure a Playwright run cannot warn about, because the
 * suite in question is the one that does not run. TypeScript does not catch
 * it either: `page.goto` takes `waitUntil?: 'load'|'domcontentloaded'|...`,
 * and these specs are not in the typecheck pass. So the check is here, in the
 * suite that DOES run on every push, and it reads the spec files as text.
 */

import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/** The four values Playwright accepts for `waitUntil`. */
const ACCEPTED = ['load', 'domcontentloaded', 'networkidle', 'commit']

const E2E_ROOT = new URL('../e2e', import.meta.url).pathname

/**
 * Every Playwright spec under tests/e2e, at any depth.
 *
 * @param {string} dir Directory to walk.
 * @return {string[]} Absolute paths.
 */
function specFiles(dir) {
	const found = []
	for (const entry of readdirSync(dir)) {
		const path = join(dir, entry)
		if (statSync(path).isDirectory()) {
			found.push(...specFiles(path))
			continue
		}
		if (path.endsWith('.spec.ts') || path.endsWith('.spec.js')) {
			found.push(path)
		}
	}
	return found
}

describe('every e2e waitUntil is a value Playwright accepts', () => {
	it('names no identifier, object or template where a string literal belongs', () => {
		const offenders = []

		for (const path of specFiles(E2E_ROOT)) {
			const lines = readFileSync(path, 'utf8').split('\n')
			lines.forEach((line, index) => {
				const match = line.match(/waitUntil:\s*(.+?)\s*[,}]/)
				if (match === null) {
					return
				}

				const value = match[1].replace(/^['"]|['"]$/g, '')
				if (ACCEPTED.includes(value)) {
					return
				}

				offenders.push(
					`${path.slice(E2E_ROOT.length + 1)}:${index + 1} -> ${match[1]}`,
				)
			})
		}

		expect(
			offenders,
			'Playwright throws on an unaccepted waitUntil before the test body '
				+ 'runs, so these scenarios cannot pass or fail anywhere. To pass the '
				+ 'measured page-load budget, pass PAGE_LOAD as the whole options '
				+ `object: page.goto(url, PAGE_LOAD). Offenders:\n${offenders.join('\n')}`,
		).toEqual([])
	})
})
