/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A reader arranges the dashboard they read, and is offered two lists they
 * could not have configured themselves.
 *
 * Both declarations fail SILENTLY when they are wrong, which is the only
 * reason they are worth a test. `userLayout` on a page CnDashboardPage does
 * not read renders exactly the page that never mentioned it, and so does
 * `userLayout` on a page that reads it for a user who never dragged a widget:
 * those two states are indistinguishable on screen. A preset whose filter
 * carries a token nothing resolves is sent as the literal string, matches no
 * row, and arrives as a card that is permanently empty rather than refused.
 *
 * So the assertions here are about the DECLARATION rather than the render:
 * every dashboard carries the key and nothing else does, every preset carries
 * its own register and schema so the reader is asked for neither, every filter
 * key is a property the case schema actually declares or a lens OpenRegister
 * reserves, and every sentinel token in a preset filter is one the manifest
 * schema's own pattern admits.
 *
 * @spec openspec/changes/archive/2026-09-20-a-dashboard-the-reader-arranges/specs/dashboard/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read a JSON file from the repository.
 *
 * @param {...string} parts Path parts under the repository root.
 * @return {object} The parsed document.
 */
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const manifest = readJson('src', 'manifest.json')
const schema = readJson('tests', 'schemas', 'app-manifest-v2.schema.json')

/** The five pages a handler or a team lead lands on. */
const DASHBOARDS = [
	'Dashboard',
	'MyWorkHome',
	'Doorlooptijd',
	'ProcessMiningDashboard',
	'TermijnDashboard',
]

/**
 * One page of the shipped manifest.
 *
 * @param {string} id The page id.
 * @return {object} The page entry.
 */
function page(id) {
	const found = (manifest.pages || []).find((entry) => entry.id === id)
	expect(found, `the manifest has no page "${id}"`).toBeTruthy()
	return found
}

/**
 * Every property the `case` schema carries, gathered from the base register
 * and from every `register.d` fragment that extends it. A filter key checked
 * against the base alone would reject `isTemplate`, which is declared in a
 * fragment and is on the Queue page today.
 *
 * @return {Set<string>} The declared property names.
 */
function caseProperties() {
	const names = new Set()
	/**
	 * Collect the `case` properties one document declares.
	 *
	 * @param {object} doc A register document or fragment.
	 * @return {void}
	 */
	const collect = (doc) => {
		const schemas = doc && doc.components && doc.components.schemas
		const entry = schemas && schemas.case
		Object.keys((entry && entry.properties) || {}).forEach((k) => names.add(k))
	}
	collect(readJson('lib', 'Settings', 'dossiq_register.json'))
	const dir = path.join(ROOT, 'lib', 'Settings', 'register.d')
	fs.readdirSync(dir)
		.filter((f) => f.endsWith('.json'))
		.forEach((f) => collect(readJson('lib', 'Settings', 'register.d', f)))
	return names
}

/** Lens and context keys OpenRegister reserves, which are not case properties. */
const RESERVED_FILTER_KEYS = new Set([
	'_watching',
	'_unread',
	'_favourite',
	'_recent',
	'_archived',
])

describe('a reader keeps their own arrangement of a dashboard', () => {
	it.each(DASHBOARDS)('%s declares userLayout', (id) => {
		expect(page(id).config.userLayout).toBe(true)
	})

	it('declares it on the dashboards and nowhere else', () => {
		const carrying = (manifest.pages || [])
			.filter((p) => p.config && p.config.userLayout)
			.map((p) => p.id)
		expect(carrying.sort()).toEqual([...DASHBOARDS].sort())
	})

	it('leaves membership with the manifest: every dashboard still ships widgets', () => {
		for (const id of DASHBOARDS) {
			const widgets = page(id).config.widgets || []
			expect(widgets.length, `${id} ships no widgets`).toBeGreaterThan(0)
		}
	})
})

describe('the two lists a handler wants are offered by name', () => {
	const presets = page('Dashboard').config.userWidgets || []

	it('offers exactly the two presets, each with an id, a kind and a label', () => {
		expect(presets.map((p) => p.id)).toEqual([
			'cases-you-follow',
			'your-teams-queue',
		])
		for (const preset of presets) {
			expect(preset.kind, `${preset.id} has no kind`).toBeTruthy()
			expect(preset.label, `${preset.id} has no label`).toBeTruthy()
		}
	})

	it('asks the reader for neither a register nor a schema', () => {
		for (const preset of presets) {
			const content = preset.widget.content
			expect(content.register, `${preset.id} names no register`).toBe('dossiq')
			expect(content.schema, `${preset.id} names no schema`).toBe('case')
			expect(
				content.filter,
				`${preset.id} carries no filter, so the reader has to write one`,
			).toBeTruthy()
		}
	})

	it('filters only on keys the case schema declares or OpenRegister reserves', () => {
		const declared = caseProperties()
		for (const preset of presets) {
			for (const key of Object.keys(preset.widget.content.filter)) {
				const known = declared.has(key) || RESERVED_FILTER_KEYS.has(key)
				expect(
					known,
					`${preset.id} filters on "${key}", which is neither a case property nor a reserved lens, so OpenRegister answers the unfiltered set`,
				).toBe(true)
			}
		}
	})

	it('uses no sentinel token the manifest schema does not admit', () => {
		const pattern = new RegExp(schema.$defs.sentinelFilterToken.pattern)
		for (const preset of presets) {
			for (const [key, value] of Object.entries(
				preset.widget.content.filter,
			)) {
				if (typeof value !== 'string' || !value.startsWith('@')) {
					continue
				}
				expect(
					pattern.test(value),
					`${preset.id} filters ${key} on "${value}", which resolveFilterTokens does not resolve, so the literal string is sent and the card is always empty`,
				).toBe(true)
			}
		}
	})

	it('names a row route, so a preset row opens the case it names', () => {
		const routed = new Set((manifest.pages || []).map((p) => p.id))
		for (const preset of presets) {
			expect(routed.has(preset.widget.content.rowRoute)).toBe(true)
		}
	})
})
