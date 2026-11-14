/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The features and roadmap page in its board look (DqFunctionaliteiten): the
 * line under the title names the page, the version and how often it changes,
 * and the notice under the groups points to the comparison on conduction.nl.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
const board = manifest.pages.find((page) => page.id === 'FeaturesRoadmap').config.board

describe('the features and roadmap page', () => {
	it('opens the line under the title with the page name, as the board does', () => {
		expect(board.subtitle).toBe('Features and roadmap · version {appVersion} · updated with every release')
		expect(nl[board.subtitle]).toBe('Functionaliteiten en roadmap · versie {appVersion} · bijgewerkt bij elke release')
	})

	it('names the status filter Toon, as the board does', () => {
		expect(board.filterLabel).toBe('Show only')
		expect(nl[board.filterLabel]).toBe('Toon')
	})

	it('keeps the notice that points to the comparison on conduction.nl', () => {
		expect(board.notice).toMatch(/^How dossiq compares to other case systems/)
		expect(board.noticeLink.text).toBe('conduction.nl')
	})
})
