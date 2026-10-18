/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A dashboard's layout is the administrator's, through buildiq, and a reader
 * is offered two lists they could not have configured themselves.
 *
 * The preset declarations fail SILENTLY when they are wrong, which is the
 * only reason they are worth a test. A preset whose filter
 * carries a token nothing resolves is sent as the literal string, matches no
 * row, and arrives as a card that is permanently empty rather than refused.
 *
 * So the assertions here are about the DECLARATION rather than the render:
 * no page keeps a reader's own arrangement, every preset carries
 * its own register and schema so the reader is asked for neither, every filter
 * key is a property the case schema actually declares or a lens OpenRegister
 * reserves, and every sentinel token in a preset filter is one the manifest
 * schema's own pattern admits.
 *
 * @spec openspec/specs/dashboard/spec.md
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
	// Not MyWorkHome: its widgets live in its views, not on its own grid,
	// so it is checked on its own below (landing-view-layouts).
	'Dashboard',
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

describe('the layout of a dashboard is the administrator\'s, through buildiq', () => {
	it('keeps no reader\'s own arrangement on any page', () => {
		// Ruben, 18 October 2026: layout is buildiq's. See layoutIsBuildiqs.spec.js
		// for why `userLayout` would keep buildiq's geometry out of the manifest.
		const carrying = (manifest.pages || [])
			.filter((p) => p.config && 'userLayout' in p.config)
			.map((p) => p.id)
		expect(carrying).toEqual([])
	})

	it('draws no in-page Edit button on any page: changing the layout is buildiq\'s', () => {
		// Ruben, 18 October 2026: "indeling aanpassen needs to go everywhere,
		// that's a buildiq functionality under the buildiq button".
		// CnDashboardPage draws its Edit toggle only with `allowEdit`.
		const editable = [
			...(manifest.pages || []),
			...(readJson('src', 'menu-layout.simple.json').pages || []),
			...(readJson('src', 'menu-layout.json').pages || []),
		].filter((p) => p.config && p.config.allowEdit === true)
		expect(editable.map((p) => p.id)).toEqual([])
	})

	it('leaves membership with the manifest: every dashboard still ships widgets', () => {
		for (const id of DASHBOARDS) {
			const widgets = page(id).config.widgets || []
			expect(widgets.length, `${id} ships no widgets`).toBeGreaterThan(0)
		}
	})
})

describe('the landing page keeps its views', () => {
	it('declares its views, the app id and a stable page id', () => {
		const home = page('MyWorkHome')
		expect(home.config.appId).toBe('dossiq')
		expect(home.config.pageId).toBe('MyWorkHome')
		expect(home.config.views.length).toBeGreaterThan(0)
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

	it("narrows the team queue to the reader's own teams, unclaimed", () => {
		// my-teams-queue: a team is a Nextcloud group and `@myGroups` is the
		// reader's group ids, an IN filter. While they load, or for a reader
		// in no group, the list waits and shows its prompt.
		const queue = presets.find((p) => p.id === 'your-teams-queue').widget.content
		expect(queue.filter.assignedGroup).toBe('@myGroups')
		expect(queue.filter.assignee).toBe('IS NULL')
		expect(queue.prompt).toBeTruthy()
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
