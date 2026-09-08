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

const manifest = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))
const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
const widgets = caseDetail.config.widgets
const documents = widgets.find((widget) => widget.id === 'case-documents')
const panels = widgets.find((widget) => widget.id === 'case-panels')
const tabs = panels.content.tabs

describe('CaseDetail Documents tab', () => {
	it('declares a case-documents widget', () => {
		expect(
			documents,
			'CaseDetail must declare the case-documents widget',
		).toBeTruthy()
		expect(documents.title).toBe('Documents')
	})

	it('sits in the tab strip before Files', () => {
		const ids = tabs.map((tab) => tab.widgetId)
		expect(ids).toContain('case-documents')
		expect(ids.indexOf('case-documents')).toBeLessThan(ids.indexOf('case-files'))
	})

	it('keeps the Files tab for loose attachments', () => {
		const files = tabs.find((tab) => tab.widgetId === 'case-files')
		expect(files, 'D5: removing Files would drop the share surface').toBeTruthy()
		expect(files.label).toBe('Files')
	})

	it('stays out of layout, so the tabs widget renders it once', () => {
		const placed = caseDetail.config.layout.map((entry) => entry.widgetId)
		expect(placed).not.toContain('case-documents')
	})

	it('names a widget type the component registry answers to', () => {
		const registry = fs.readFileSync(REGISTRY_PATH, 'utf8')
		expect(documents.type).not.toBe('custom')
		expect(
			registry.includes(`'${documents.type}': {`),
			`src/registry.js must register the widget type "${documents.type}"`,
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
