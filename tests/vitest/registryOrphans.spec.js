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
		expect(
			modalEntries().length,
			'The regex found almost no registry entries, so every assertion below '
			+ 'would pass without examining anything. The registry formatting has '
			+ 'changed: fix `modalEntries()` before trusting this file again.',
		).toBeGreaterThanOrEqual(22)
	})

	it('names every registered modal from at least one manifest action', () => {
		const targets = manifestTargets()
		const orphans = modalEntries()
			.filter((entry) => !entry.hasReason)
			.filter((entry) => !targets.has(entry.name))
			.map((entry) => entry.name)

		expect(
			orphans,
			'These modals are registered and no manifest action opens them, so no '
			+ 'user can reach them. Do one of two things, and not a third. ROUTE it: '
			+ 'add an `open-modal` action naming it to the page that should offer it, '
			+ 'in src/manifest.json. RETIRE it: delete the component, its import and '
			+ 'its registry entry, and say in the PR what the deletion takes with it. '
			+ 'Only when neither is a decision you can take, add `_orphanReason` to '
			+ 'its registry entry saying what is missing and what would make it '
			+ 'reachable. A reason is not a place to park a dialog nobody wants.',
		).toEqual([])
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

/**
 * Dialog and modal files nothing imports, and the verdict on each.
 *
 * THE SECOND HOLE, AND WHY THE FIRST CHECK CANNOT SEE IT. Everything above
 * compares the registry with the manifest. A component that was never
 * registered is in neither, so it is invisible to that comparison: the only
 * occurrence of `BerichtenboxComposeDialog` in the whole repository was its
 * own `name:` line, and the registry check would have passed forever.
 *
 * Eight files failed this on its first run. Five carried no test and nothing
 * else referenced them, and were retired in the same change: StatusTransitionDialog
 * and ConsultationCreateDialog and ConsultationResponseForm and
 * DeleteChecklistDialog and RenewalRequestModal. Each was measured first, and
 * none did network work on mount, so none took a side effect with it.
 *
 * What is left is listed below, with what would remove it from the list.
 */
const KNOWN_UNIMPORTED = {
	'src/dialogs/CaseTransitionConfirmDialog.vue':
		'RETIRE, and the cost is why it is still here. A case transition is '
		+ 'already served by two surfaces a person reaches on CaseDetail: the '
		+ 'stages widget and CaseLifecycleMenuDialog, which reads '
		+ '/available-transitions, /lifecycle and /acts and posts the move '
		+ 'itself. So the rule says retire. What retiring takes with it is three '
		+ 'test files that mount this component and assert on it: '
		+ 'caseTransitionOutcome, workflowBoardMove and resultTemplateOnClose. '
		+ 'They assert about a component nothing renders, so they cover nothing '
		+ 'that runs, but deleting 400 lines of assertions is a coverage decision '
		+ 'and not a routing one. It does no network work on mount.',
	'src/dialogs/DsoCaseDetail.vue':
		'RETIRE. It posts to /apps/dossiq/api/dso/cases/, and no page, route or '
		+ 'schema in the manifest resolves a DSO case: the only DSO surface is '
		+ 'DSOIntakeController, which is a machine-to-machine intake endpoint '
		+ 'with no reader. Held back with the one above because '
		+ 'dialogTemplateBindings mounts it, and that file also covers dialogs '
		+ 'that are alive, so it is an edit rather than a deletion. No network '
		+ 'work on mount.',
}

/**
 * Every `.vue` file under the dialog and modal folders.
 *
 * @return {string[]} Repository-relative paths.
 */
function surfaceFiles() {
	return ['src/modals', 'src/dialogs'].flatMap((dir) =>
		fs
			.readdirSync(path.join(ROOT, dir))
			.filter((name) => name.endsWith('.vue'))
			.map((name) => `${dir}/${name}`),
	)
}

/**
 * Every source file that could import one of them.
 *
 * @return {Array<{path: string, text: string}>} Paths and contents.
 */
function sourceFiles() {
	const out = []
	const walk = (dir) => {
		for (const entry of fs.readdirSync(path.join(ROOT, dir), {
			withFileTypes: true,
		})) {
			const child = `${dir}/${entry.name}`
			if (entry.isDirectory()) {
				walk(child)
			} else if (/\.(js|ts|vue)$/.test(entry.name)) {
				out.push({
					path: child,
					text: fs.readFileSync(path.join(ROOT, child), 'utf8'),
				})
			}
		}
	}
	walk('src')
	return out
}

/**
 * The dialog and modal files nothing in src/ imports.
 *
 * @return {string[]} Repository-relative paths.
 */
function unimportedSurfaces() {
	const sources = sourceFiles()
	return surfaceFiles().filter((file) => {
		const base = file.split('/').pop()
		return !sources.some(
			(source) => source.path !== file && source.text.includes(base),
		)
	})
}

describe('every dialog file is imported by something', () => {
	it('examines every dialog and modal file', () => {
		// The same vacuous-pass guard as above: an empty folder listing would
		// make the assertion below pass without looking at anything.
		expect(
			surfaceFiles().length,
			'Almost no dialog files were found, so the check below examined '
			+ 'nothing. Fix surfaceFiles() before trusting this file.',
		).toBeGreaterThanOrEqual(40)
	})

	it('imports each one, or records why it cannot be reached', () => {
		const undeclared = unimportedSurfaces().filter(
			(file) => !(file in KNOWN_UNIMPORTED),
		)

		expect(
			undeclared,
			'Nothing in src/ imports these components, so they cannot render at '
			+ 'all and no user can reach them. Do one of two things. ROUTE it: '
			+ 'import it, register it in src/registry.js and name it from a '
			+ 'manifest action. RETIRE it: delete it, and say in the PR what the '
			+ 'deletion takes with it, having first checked what it does on mount, '
			+ 'because a component deleted for its looks once took a mount-time '
			+ 'write with it. Add it to KNOWN_UNIMPORTED only when neither is a '
			+ 'decision you can take, with the reason and what would change it.',
		).toEqual([])
	})

	it('drops an entry that has stopped being true', () => {
		// Without this, the list only ever grows, and an entry for something
		// somebody quietly re-homed goes on claiming it is broken.
		const unimported = unimportedSurfaces()
		for (const file of Object.keys(KNOWN_UNIMPORTED)) {
			expect(
				unimported.includes(file),
				`${file} is listed as unreachable and something imports it now. `
				+ 'Remove it from KNOWN_UNIMPORTED.',
			).toBe(true)
		}
	})
})
