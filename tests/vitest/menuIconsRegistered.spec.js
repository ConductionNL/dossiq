/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every menu entry in the manifest and its fragments names an icon that
 * src/icons.js registers. An unregistered name renders no icon at all in the
 * navigation, not a fallback glyph (ADR-077 rule 3).
 *
 * @spec openspec/specs/menu-board-icons/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const icons = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
const fragmentDir = path.join(ROOT, 'src', 'manifest.d')
const manifests = [
	['src/manifest.json', path.join(ROOT, 'src', 'manifest.json')],
	...fs.readdirSync(fragmentDir).filter((name) => name.endsWith('.json')).sort()
		.map((name) => [`src/manifest.d/${name}`, path.join(fragmentDir, name)]),
]

function menuIcons(entries, out = []) {
	for (const entry of Array.isArray(entries) ? entries : []) {
		if (typeof entry?.icon === 'string' && entry.icon !== '') {
			out.push([entry.id, entry.icon])
		}
		menuIcons(entry?.children, out)
	}
	return out
}

describe('menu icons', () => {
	it.each(manifests)('%s only names icons dossiq registers', (_label, file) => {
		const manifest = JSON.parse(fs.readFileSync(file, 'utf8'))
		for (const [id, icon] of menuIcons(manifest.menu)) {
			expect(icons, `${id}: ${icon}`).toMatch(new RegExp(`^\\t${icon},$`, 'm'))
		}
	})
})
