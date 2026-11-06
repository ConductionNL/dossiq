/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqHandhaving Classificatie: the classification as the LHS matrix's codes,
 * "Gedrag 2 · Gevolg C · Vak C2 van 16", with the matrix version as a muted
 * line under them. The facts widget joining parts and maps is tested in
 * nextcloud-vue; this holds the card config to the board.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'src/manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(root, 'l10n/nl.json'), 'utf8')).translations
const card = manifest.pages.find((page) => page.id === 'LhsRecommendationDetail').config.sideColumn.find((entry) => entry.id === 'side-classification')
const [behaviour, consequence, cell, version] = card.content.fields

describe('the Classificatie card (DqHandhaving)', () => {
	it('codes behaviour as the matrix row and consequence as its column', () => {
		expect(behaviour.map).toEqual({ goedwillend: '1', onverschillig: '2', calculerend: '3', crimineel: '4' })
		expect(consequence.map).toEqual({ gering: 'A', aanzienlijk: 'B', ernstig: 'C', zeer_ernstig: 'D' })
	})

	it('names the cell as column then row, out of the matrix\'s 16', () => {
		expect(nl[cell.label]).toBe('Vak')
		const [code, total] = cell.parts
		expect(code.separator).toBe('')
		expect(code.parts.map((part) => [part.path, part.map])).toEqual([['severity', consequence.map], ['behaviour', behaviour.map]])
		expect(nl[total.text]).toBe('van 16')
	})

	it('keeps the matrix version as a muted line', () => {
		expect(version.as).toBe('line')
		expect(version.parts).toEqual([{ path: 'matrixVersion', label: 'Matrix version' }])
	})
})
