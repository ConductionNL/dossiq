// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The "Synced from" tab (board integriq/IqGesynchroniseerdVan): two library
 * widgets over integriq's provenance endpoint, shown only for a case a
 * synchronization wrote.
 *
 * @spec openspec/changes/case-synced-from-tab/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations

const caseDetail = manifest.pages.find((p) => p.id === 'CaseDetail')
const strip = caseDetail.config.widgets.find((w) => w.id === 'case-panels')
const tab = strip.content.tabs.find((t) => t.widgetId === 'case-synced-from-panel')
const panel = caseDetail.config.widgets.find((w) => w.id === 'case-synced-from-panel')
const widgets = Object.fromEntries(panel.content.sections.map((section) => [section.widget.id, section.widget]))
const IDS = ['case-synced-from', 'case-synced-from-log']
const PROVENANCE = '/apps/integriq/api/provenance'

describe('the Synced from tab', () => {
	it('is one group tab holding the facts card and the log', () => {
		expect(tab.label).toBe('Synced from')
		expect(panel.type).toBe('sections')
		expect(Object.keys(widgets)).toEqual(IDS)
	})

	it('shows only for a case a synchronization wrote', () => {
		expect(tab.visibleWhen).toEqual({
			appInstalled: 'integriq',
			endpoint: `${PROVENANCE}?register=dossiq&schema=case&id=@objectId`,
			field: 'synced',
			op: 'eq',
			value: true,
		})
	})

	it('reads integriq provenance for this case', () => {
		for (const id of IDS) {
			expect(widgets[id].content.endpointSource).toMatchObject({
				url: PROVENANCE,
				params: { register: 'dossiq', schema: 'case', id: '@objectId' },
			})
		}
		expect(widgets['case-synced-from-log'].content.endpointSource.responsePath).toBe('recent')
	})

	it('translates what it shows into Dutch', () => {
		const strings = [tab.label, ...panel.content.sections.map((section) => section.label)]
		for (const id of IDS) {
			const c = widgets[id].content
			strings.push(widgets[id].title, c.emptyText, c.link?.label)
			for (const f of (c.fields || [])) strings.push(f.label, f.text)
			for (const col of (c.columns || [])) strings.push(col.label)
		}
		for (const s of strings.filter((x) => typeof x === 'string')) {
			expect([s, typeof nl[s]]).toEqual([s, 'string'])
		}
	})
})
