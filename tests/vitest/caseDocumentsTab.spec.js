/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Documents tab's manifest-to-registry contract.
 *
 * A tab child renders through CnTabsWidget → CnDetailWidgetHost, which
 * resolves a renderer by widget TYPE against the consumer registry. A
 * `type: "custom"` widget resolves through the PAGE's `widget-<id>` slot
 * instead, and CnDetailPage renders that slot only for layout grid items — so
 * a custom widget named in a tab renders nothing, logs nothing, and passes
 * every manifest check. This spec is the thing that fails instead: the tab's
 * widget type must be a key this app's registry answers to.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const MANIFEST_PATH = path.resolve(__dirname, '../../src/manifest.json')
const REGISTRY_PATH = path.resolve(__dirname, '../../src/registry.js')
const ICONS_PATH = path.resolve(__dirname, '../../src/icons.js')

const { caseTabOf, caseWidget } = require('./helpers/casePanels.js')

const manifest = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))
const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
// Looked up through the shared helper rather than in `config.widgets`: since
// the strip came down from fourteen tabs to six, this widget is a SECTION of
// the Documents tab, so a top-level `find` returns undefined and every
// assertion below would read as "the widget was deleted".
const documents = caseWidget('case-documents')

describe('CaseDetail Documents tab', () => {
	it('declares a case-documents widget', () => {
		expect(
			documents,
			'CaseDetail must declare the case-documents widget',
		).toBeTruthy()
		expect(documents.title).toBe('Documents')
	})

	it('is the first section of the Documents tab', () => {
		const where = caseTabOf('case-documents')
		expect(where, 'case-documents is not reachable from the strip').toBeTruthy()
		expect(where.tab).toBe('Documents')
		expect(where.label).toBe('Documents')
	})

	it('keeps Files for loose attachments, under it in the same tab', () => {
		// D5 said removing Files would drop the files leaf's share and comment
		// surface, and it was right. Files did not leave the page when the strip
		// came down to six tabs, it left the STRIP: it is the second section of
		// this tab, below the dossier, which is the order that says which one is
		// the case file.
		const files = caseTabOf('case-files')
		expect(files, 'D5: removing Files would drop the share surface').toBeTruthy()
		expect(files.tab).toBe('Documents')
		expect(files.label).toBe('Files')

		const sections = caseWidget('case-documents-panel').content.sections.map(
			(section) => section.widget.id,
		)
		expect(sections.indexOf('case-documents')).toBeLessThan(
			sections.indexOf('case-files'),
		)
	})

	it('stays out of layout, so the tabs widget renders it once', () => {
		const placed = caseDetail.config.layout.map((entry) => entry.widgetId)
		expect(placed).not.toContain('case-documents')
	})

	it('names a widget type the library resolves as a built-in, or the app registers', () => {
		// `object-list` is a nextcloud-vue BUILT-IN, resolved by the library
		// directly and never listed in this app's own registry.js — unlike the
		// retired `dossier-tab` widget TYPE this tab used to declare. The
		// custom-vs-registered distinction this spec guards still holds: a
		// `type: "custom"` widget renders nothing inside a tab, and any type
		// this app names that is NOT the known library built-in must still be
		// one this app's registry answers to.
		const registry = fs.readFileSync(REGISTRY_PATH, 'utf8')
		expect(documents.type).not.toBe('custom')
		const isLibraryBuiltIn = documents.type === 'object-list'
		expect(
			isLibraryBuiltIn || registry.includes(`'${documents.type}': {`),
			`"${documents.type}" must be a known nextcloud-vue built-in, or src/registry.js must register it`,
		).toBe(true)
	})

	it('names an icon src/icons.js registers', () => {
		const icons = fs.readFileSync(ICONS_PATH, 'utf8')
		expect(icons).toContain(`\t${documents.icon},`)
	})

	it('says so when the case has no documents', () => {
		expect(documents.content.emptyText).toBe('No documents yet')
	})
})
