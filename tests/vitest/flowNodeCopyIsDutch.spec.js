/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The flow steps dossiq offers read in Dutch in the step palette.
 *
 * Their labels and descriptions are PHP strings (lib/Flow), which the frontend
 * l10n check does not scan, so a step named in English reached the board's
 * palette unnoticed (DqFlow showed "Set case status").
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const FLOW_DIR = path.join(ROOT, 'lib', 'Flow')
const nl = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8'),
).translations

const keys = fs
	.readdirSync(FLOW_DIR)
	.filter((name) => name.endsWith('.php'))
	.flatMap((name) => {
		const source = fs.readFileSync(path.join(FLOW_DIR, name), 'utf8')
		// A key may be split over several literals joined with `.`.
		return [
			...source.matchAll(/->t\(\s*((?:'(?:[^'\\]|\\.)*'\s*\.?\s*)+)/g),
		].map((m) => [
			name,
			[...m[1].matchAll(/'((?:[^'\\]|\\.)*)'/g)]
				.map((part) => part[1].replace(/\\'/g, "'"))
				.join(''),
		])
	})

describe('the flow steps dossiq offers', () => {
	it('finds the strings it checks', () => {
		expect(keys.length).toBeGreaterThan(5)
	})

	it.each(keys)('%s: "%s" has a Dutch entry', (file, key) => {
		expect(nl[key], `nl entry for "${key}"`).toBeTruthy()
	})
})
