/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A registered dialog that no page opens fails this suite.
 *
 * WHY THIS FILE EXISTS. `src/registry.js` maps a name to a component, and
 * `src/manifest.json` names that key as an `open-modal` target. Nothing
 * compared the two. On 2026-09-13 the Documents tab was retired and replaced
 * by the `case-files` leaf, and the two dialogs its row and bulk actions
 * opened, VersionHistoryPanel and BulkDocumentActionDialog, stayed registered,
 * stayed imported, stayed unit tested and became unreachable. Their registry
 * notes went on describing the tab that no longer existed. Every gate was
 * green: the manifest validator checks shapes, and the registry only answers
 * for a key it is asked for.
 *
 * A third one fell out of writing this: CaseLifecycleActionDialog, whose note
 * claimed four header actions opened it while CaseLifecycleMenuDialog had
 * taken the gestures over. It carries an `_orphanReason` now, which is the
 * escape hatch: a dialog may be registered with no manifest caller when the
 * reason is written down beside it.
 *
 * THE COUNT IS ASSERTED. A loop that matched nothing passes every assertion
 * inside it, so the number of entries examined is checked too: a regex that
 * stops matching the registry's shape turns this file red instead of green.
 *
 * @spec openspec/changes/document-acts-reach-a-surface/specs/document-zaakdossier/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const REGISTRY_PATH = path.join(ROOT, 'src', 'registry.js')
const MANIFEST_PATH = path.join(ROOT, 'src', 'manifest.json')
const MANIFEST_D = path.join(ROOT, 'src', 'manifest.d')

const registrySource = fs.readFileSync(REGISTRY_PATH, 'utf8')

/**
 * Every `kind: 'modal'` entry of the registry, with whether it carries a
 * written reason for having no caller.
 *
 * Read from the SOURCE rather than by importing the module: importing pulls
 * in every `.vue` file the registry names, which is the whole app, and this
 * check is about two text files agreeing with each other.
 *
 * @return {Array<{name: string, hasReason: boolean}>} The modal entries.
 */
function modalEntries() {
	const entries = []
	const pattern = /^\t([A-Za-z0-9_]+):\s*\{\n((?:\t\t.*\n)*?)\t\},/gm
	let match = pattern.exec(registrySource)
	while (match !== null) {
		const [, name, body] = match
		if (body.includes("kind: 'modal',")) {
			entries.push({ name, hasReason: /_orphanReason:/.test(body) })
		}
		match = pattern.exec(registrySource)
	}
	return entries
}

/**
 * Every modal target any manifest action names, across the base manifest and
 * every fragment under `src/manifest.d`.
 *
 * Only real `target` values count. A name that appears in a `_note` does not:
 * that is exactly how CaseLifecycleActionDialog read as wired while nothing
 * opened it.
 *
 * @return {Set<string>} The targets.
 */
function manifestTargets() {
	const targets = new Set()
	const files = [MANIFEST_PATH]
	if (fs.existsSync(MANIFEST_D)) {
		fs.readdirSync(MANIFEST_D)
			.filter((name) => name.endsWith('.json'))
			.forEach((name) => files.push(path.join(MANIFEST_D, name)))
	}

	/**
	 * Collect `target` from every action-shaped node in a manifest tree.
	 *
	 * @param {*} node Any manifest node.
	 * @return {void}
	 */
	const walk = (node) => {
		if (Array.isArray(node)) {
			node.forEach(walk)
			return
		}
		if (node === null || typeof node !== 'object') {
			return
		}
		if (node.type === 'open-modal' && typeof node.target === 'string') {
			targets.add(node.target)
		}
		Object.values(node).forEach(walk)
	}

	files.forEach((file) => walk(JSON.parse(fs.readFileSync(file, 'utf8'))))
	return targets
}

describe('registry modals reach a surface', () => {
	it('examines every modal entry the registry declares', () => {
		// 🔴 THE GUARD AGAINST A LOOP THAT MATCHED NOTHING. If the registry's
		// formatting changes and the regex stops finding entries, every
		// assertion below passes vacuously. This one does not.
		expect(modalEntries().length).toBeGreaterThanOrEqual(22)
	})

	it('names every registered modal from at least one manifest action', () => {
		const targets = manifestTargets()
		const orphans = modalEntries()
			.filter((entry) => !entry.hasReason)
			.filter((entry) => !targets.has(entry.name))
			.map((entry) => entry.name)

		expect(orphans).toEqual([])
	})

	it('opens the two dialogs the retired Documents tab left behind', () => {
		const targets = manifestTargets()
		expect(targets.has('VersionHistoryPanel')).toBe(true)
		expect(targets.has('BulkDocumentActionDialog')).toBe(true)
	})

	it('accepts a written reason in place of a caller', () => {
		const withReason = modalEntries().filter((entry) => entry.hasReason)
		expect(withReason.map((entry) => entry.name)).toContain(
			'CaseLifecycleActionDialog',
		)
	})
})
