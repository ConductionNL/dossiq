// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The case's Timeline tab is the library `timeline` widget in manage mode.
 *
 * The kind chips (All, then each kind present with counts, filtering without
 * a second read), the CSV export of what the chips show and the board look of
 * the heading and chips are pinned by nextcloud-vue
 * (tests/components/CnTimelineManage.spec.js). What is pinned here is the
 * dossiq half: the tab declares manage mode, keeps its own heading and file
 * name, and the heading is translated by dossiq.
 *
 * @spec openspec/specs/case-history-surface/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations

const pane = manifest.pages
	.find((page) => page.id === 'CaseDetail')
	.config.widgets.find((widget) => widget.id === 'case-timeline-panel')

describe('the case Timeline tab', () => {
	it('is the library timeline in manage mode', () => {
		expect(pane.type).toBe('timeline')
		expect(pane.content.manage).toBe(true)
	})

	it('keeps the case heading and the case-history file name', () => {
		expect(pane.content.title).toBe('History of the case')
		expect(nl['History of the case']).toBeTruthy()
		expect(pane.content.exportName).toBe('case-history')
	})

	it('translates the Reply label', () => {
		expect(nl.Reply).toBeTruthy()
	})
})
