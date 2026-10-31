/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every icon the manifests name is in the icon registry: an unregistered name
 * draws the unknown-icon glyph (DqZaakTaken "Wat hierna komt", r32).
 *
 * @spec openspec/specs/case-page/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const read = (file) => fs.readFileSync(path.resolve(__dirname, '../../', file), 'utf8')

describe('manifest icons', () => {
	it('are all registered', () => {
		const registry = new Set([...read('src/icons.js').matchAll(/^\t([A-Z][A-Za-z0-9]+),$/gm)].map((m) => m[1]))
		const named = new Set()
		for (const file of ['src/menu-layout.simple.json', 'src/manifest.json']) {
			for (const m of read(file).matchAll(/"icon":\s*"([A-Z][A-Za-z0-9]+)"/g)) {
				named.add(m[1])
			}
		}
		expect([...named].filter((name) => !registry.has(name))).toEqual([])
	})
})
