/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A tour task that names a menu item must name the label that item carries.
 *
 * The getting-started tour said "Click Cases in the menu" while the item it
 * points at is "All cases" in both structures. In the simple structure
 * "Cases" is a caption, so the one word the tour gave a new user was the one
 * entry in the menu that does nothing. Case types and Flows sit in the
 * Advanced foldout, and the tour said to open them "in the menu".
 *
 * Both menus are built with the library's REAL `buildManifest`, the way
 * structureProfile.spec.js builds them, so a label or a section that moves
 * in the manifest, a fragment or a layout file moves under this test too.
 *
 * @spec openspec/changes/r4-tour-menu-labels-and-settings-styles/specs/getting-started-tour/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
/**
 * Read a JSON file under the app root.
 *
 * @param {...string} parts Path segments.
 * @return {object} The parsed file.
 */
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))

/**
 * The foldout CnAppNav renders `section: "settings"` entries under. Its title
 * is the library's string, not dossiq's, so it is named here.
 */
const SETTINGS_FOLDOUT = 'Advanced'

const STRUCTURES = {
	full: 'menu-layout.json',
	simple: 'menu-layout.simple.json',
}

/**
 * Every menu entry of one structure, flattened.
 *
 * @param {string} file The layout file.
 * @return {object[]} The entries, children included.
 */
function menuOf(file) {
	const manifest = buildProfiledManifest(
		buildManifest,
		readJson('src', 'manifest.json'),
		fragments,
		readJson('src', file),
	)
	const out = []
	const walk = (items) =>
		(items || []).forEach((item) => {
			out.push(item)
			walk(item.children)
		})
	walk(manifest.menu)
	return out
}

/**
 * The entry a nav-item target points at: by id first, then by route.
 *
 * @param {object[]} menu The flattened menu.
 * @param {string} ref The target ref.
 * @return {object|undefined} The entry.
 */
function targetOf(menu, ref) {
	return (
		menu.find((item) => item.id === ref)
		|| menu.find((item) => item.route === ref)
	)
}

const tourSteps = readJson('src', 'manifest.json')
	.walkthrough.tours.flatMap((tour) =>
		tour.steps.map((step) => ({ tour: tour.id, ...step })),
	)
	.filter((step) => step.target?.kind === 'nav-item' && step.task)

describe('tour tasks name the menu label of their target', () => {
	it('the tour has nav-item steps with a task to check', () => {
		expect(tourSteps.length).toBeGreaterThanOrEqual(3)
	})

	for (const [structure, file] of Object.entries(STRUCTURES)) {
		const menu = menuOf(file)

		it.each(tourSteps.map((step) => [step.id, step]))(
			`🔴 %s names its target's label in the ${structure} menu`,
			(_id, step) => {
				const target = targetOf(menu, step.target.ref)
				expect(target, `no menu entry for ${step.target.ref}`).toBeDefined()

				const label = target.label
				const words = new RegExp(
					`(^|\\s)${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(\\s|,|$)`,
				)
				expect(step.task).toMatch(words)

				if (target.section === 'settings') {
					expect(step.task).toBe(`Open ${SETTINGS_FOLDOUT}, then ${label}`)
				} else {
					expect(step.task).not.toMatch(new RegExp(SETTINGS_FOLDOUT))
				}
			},
		)
	}
})

describe('the Dutch catalogue follows the tour tasks', () => {
	const nl = readJson('l10n', 'nl.json').translations
	const en = readJson('l10n', 'en.json').translations

	it.each(tourSteps.map((step) => [step.task]))(
		'"%s" has an English and a Dutch entry',
		(task) => {
			expect(en[task]).toBe(task)
			expect(nl[task]).toBeTruthy()
			expect(nl[task]).not.toBe(task)
		},
	)
})
