/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * A surface nobody can reach says so here, or it fails.
 *
 * WHY. On 2026-09-13 documents-live-on-the-case retired the Documents tab.
 * `VersionHistoryPanel` and `BulkDocumentActionDialog` hung from its
 * `rowActions` and `bulkActions`; both kept their imports, their registry
 * entries, their unit tests and their registry notes describing a host that
 * no longer existed. Nothing failed. They were found five days later by a
 * person reading the manifest, not by a test.
 *
 * `GET /api/dossier/{caseId}/export` had been reachable by nobody since it
 * shipped, and `BerichtenboxComposeDialog.vue` had never been registered at
 * all: the only occurrence of its name in the repository was its own `name:`
 * line.
 *
 * WHAT THIS TEST IS. Two questions, asked mechanically:
 *
 *   1. Is every registry modal named by the manifest that is supposed to open
 *      it?
 *   2. Is every dialog and modal file imported by anything at all?
 *
 * A surface that answers no to either is dark. Dark is allowed: a component
 * can be written before its host, or wait on a sibling app. What is not
 * allowed is dark AND SILENT. An entry below is a sentence somebody wrote on
 * purpose, and the list is the inventory of what does not work today.
 *
 * WHAT IT DOES NOT CATCH. A surface the manifest names on a page nobody can
 * navigate to, and a registry note that describes the wrong host. Both are
 * real and both need a reader. This catches the case that has actually
 * happened twice.
 */

import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const ROOT = new URL('../..', import.meta.url).pathname
const manifest = readFileSync(join(ROOT, 'src/manifest.json'), 'utf8')
const registry = readFileSync(join(ROOT, 'src/registry.js'), 'utf8')

/**
 * Registry modals the manifest does not name, and why that is known.
 *
 * Remove an entry when it gets a host. Add one only with a reason a reader
 * can act on: what is missing, and what would make it reachable.
 */
const KNOWN_DARK_REGISTRY = {
	CaseLifecycleActionDialog:
		'Superseded in practice and never retired. Its registry note says the '
		+ 'stages widget opens it for Resume, and the stages widget is the '
		+ 'library `stages` widget, which cannot resolve a dossiq registry name. '
		+ "CaseDetail's `_note` says `case-resume` is a header action on the "
		+ 'page; `headerActions` holds no such id. Every lifecycle act reaches '
		+ 'CaseLifecycleMenuDialog instead. Needs a decision: retire it, or give '
		+ 'the menu a route into it.',
}

/**
 * Dialog and modal files nothing imports, and why.
 *
 * These are inherited: seven of the eight predate the work that added this
 * test, and a feature branch is not a debt sweep. They are listed so they
 * stop being invisible, and so the next one to go dark fails on the branch
 * that orphans it.
 */
const KNOWN_DARK_FILES = {
	'src/dialogs/BerichtenboxComposeDialog.vue':
		'Deliberately not registered. Its transport is MockAdapter, which '
		+ 'simulates a delivery without one. Registering it would give a handler '
		+ 'a button that hands a citizen letter to nothing. It becomes reachable '
		+ 'in openspec/changes/digital-post-consumes-integriq, task 3.1 and 3.2, '
		+ 'after the integriq adapter it depends on.',
	'src/dialogs/CaseTransitionConfirmDialog.vue':
		'Inherited. Imported by nothing; the case moves through the stages '
		+ 'widget and CaseLifecycleMenuDialog.',
	'src/dialogs/ConsultationCreateDialog.vue':
		'Inherited. Imported by nothing; no consultation surface is declared.',
	'src/dialogs/ConsultationResponseForm.vue':
		'Inherited. The other half of the same missing consultation surface.',
	'src/dialogs/DeleteChecklistDialog.vue':
		'Inherited. Imported by nothing since the procest rename at least.',
	'src/dialogs/DsoCaseDetail.vue':
		'Inherited. A detail view in the dialogs folder that no route resolves.',
	'src/dialogs/StatusTransitionDialog.vue':
		'Inherited. Imported by nothing since the procest rename at least; '
		+ 'superseded by the lifecycle menu.',
	'src/modals/RenewalRequestModal.vue':
		'Inherited. Imported by nothing; no renewal surface is declared.',
}

/**
 * Every component registered under `kind: 'modal'`.
 *
 * @return {string[]} The registry keys.
 */
function registryModals() {
	return [...registry.matchAll(/\n\t([A-Za-z0-9_]+): \{\n\t\tkind: 'modal',/g)].map(
		(match) => match[1],
	)
}

/**
 * Every `.vue` file under the dialog and modal folders.
 *
 * @return {string[]} Repository-relative paths.
 */
function surfaceFiles() {
	return ['src/modals', 'src/dialogs'].flatMap((dir) =>
		readdirSync(join(ROOT, dir))
			.filter((name) => name.endsWith('.vue'))
			.map((name) => `${dir}/${name}`),
	)
}

/**
 * Every source file that could import a surface.
 *
 * @return {Array<{path: string, text: string}>} Paths and contents.
 */
function sourceFiles() {
	const out = []
	const walk = (dir) => {
		for (const entry of readdirSync(join(ROOT, dir), { withFileTypes: true })) {
			const path = `${dir}/${entry.name}`
			if (entry.isDirectory()) {
				walk(path)
			} else if (/\.(js|ts|vue)$/.test(entry.name)) {
				out.push({ path, text: readFileSync(join(ROOT, path), 'utf8') })
			}
		}
	}
	walk('src')
	return out
}

describe('every surface is reachable, or is declared unreachable', () => {
	it('names each registry modal in the manifest', () => {
		const dark = registryModals().filter(
			(name) => !manifest.includes(`"${name}"`),
		)
		const undeclared = dark.filter((name) => !(name in KNOWN_DARK_REGISTRY))

		expect(
			undeclared,
			'These modals are registered and no manifest page opens them, so no '
			+ 'user can reach them. Give each one a home, or add it to '
			+ 'KNOWN_DARK_REGISTRY with the reason and what would make it '
			+ 'reachable.',
		).toEqual([])
	})

	it('imports each dialog and modal file somewhere', () => {
		const sources = sourceFiles()
		const dark = surfaceFiles().filter((path) => {
			const base = path.split('/').pop()
			return !sources.some(
				(source) => source.path !== path && source.text.includes(base),
			)
		})
		const undeclared = dark.filter((path) => !(path in KNOWN_DARK_FILES))

		expect(
			undeclared,
			'Nothing in src/ imports these components, so they cannot render at '
			+ 'all. Give each one a home, or add it to KNOWN_DARK_FILES with the '
			+ 'reason.',
		).toEqual([])
	})

	it('keeps the dark lists honest, so a fixed surface leaves them', () => {
		// The mirror of the two tests above. Without it an allowlist grows and
		// never shrinks, and an entry for a surface somebody quietly re-homed
		// goes on claiming it is broken.
		const modals = registryModals()
		for (const name of Object.keys(KNOWN_DARK_REGISTRY)) {
			expect(
				modals.includes(name) && !manifest.includes(`"${name}"`),
				`${name} is listed as dark and is not: remove it from KNOWN_DARK_REGISTRY.`,
			).toBe(true)
		}

		const sources = sourceFiles()
		const files = surfaceFiles()
		for (const path of Object.keys(KNOWN_DARK_FILES)) {
			const base = path.split('/').pop()
			const imported = sources.some(
				(source) => source.path !== path && source.text.includes(base),
			)
			expect(
				files.includes(path) && !imported,
				`${path} is listed as dark and is not: remove it from KNOWN_DARK_FILES.`,
			).toBe(true)
		}
	})
})
