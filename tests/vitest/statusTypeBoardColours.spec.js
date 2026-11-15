/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The statuses dossiq ships are drawn in the colours their boards draw:
 * received blue, in progress purple, waiting orange, a decision teal and
 * done green. Each colour is one the status badge paints and one the badge
 * tone map knows.
 *
 * @spec openspec/specs/case-types/spec.md#requirement-a-shipped-status-takes-the-colour-its-boards-draw
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { STATUS_COLOUR_BADGE_VARIANTS, STATUS_COLOURS } from '../../src/utils/statusColour.js'

const SETTINGS = path.resolve(__dirname, '../../lib/Settings')
const readJson = (file) => JSON.parse(fs.readFileSync(file, 'utf8'))
const inDir = (dir) => fs.readdirSync(path.join(SETTINGS, dir)).map((name) => path.join(SETTINGS, dir, name))

/**
 * Every status row in a parsed file.
 *
 * @param {unknown} node The node.
 * @param {Array<object>} out The rows found.
 * @return {Array<object>} The rows.
 */
function statuses(node, out = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => statuses(child, out))
	} else if (node && typeof node === 'object') {
		if (node['@self'] && node['@self'].schema === 'statusType' && node.name) {
			out.push(node)
		}
		if (Array.isArray(node.statusTypes)) {
			node.statusTypes.filter((row) => row && row.name).forEach((row) => out.push(row))
		}
		Object.values(node).forEach((child) => statuses(child, out))
	}
	return out
}

const files = [
	path.join(SETTINGS, 'dossiq_register.json'),
	path.join(SETTINGS, 'case_flow_seed_data.json'),
	...inDir('register.d'),
	...inDir('demo.d').filter((file) => file.endsWith('.json')),
	...inDir('templates'),
	...inDir('packages').map((dir) => path.join(dir, 'case-type.json')).filter((file) => fs.existsSync(file)),
].filter((file) => file.endsWith('.json'))
// A translatable name may ship language-keyed ({ nl, en }); the Dutch value is its source.
const rows = files.flatMap((file) => statuses(readJson(file)))
	.map((row) => (row.name && typeof row.name === 'object' ? { ...row, name: row.name.nl } : row))

describe('shipped status colours', () => {
	it('gives every shipped status a colour the badge paints', () => {
		expect(rows.length).toBeGreaterThan(150)
		for (const row of rows) {
			expect(STATUS_COLOURS, row.name).toContain(row.colour)
		}
	})

	it('draws a status the same colour wherever it is shipped', () => {
		const byName = new Map()
		for (const row of rows) {
			byName.set(row.name, [...(byName.get(row.name) || []), row.colour])
		}
		for (const [name, colours] of byName) {
			expect(new Set(colours).size, name).toBe(1)
		}
	})

	it.each([
		['Ontvangen', 'info'],
		['In behandeling', 'purple'],
		['Lakken / Anonimiseren', 'warning'],
		['Besluit', 'teal'],
		['Afgehandeld', 'success'],
		['Niet-ontvankelijk', 'error'],
	])('draws %s in the %s tone', (name, tone) => {
		const row = rows.find((candidate) => candidate.name === name)
		expect(STATUS_COLOUR_BADGE_VARIANTS[row.colour]).toBe(tone)
	})
})
