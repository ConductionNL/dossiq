/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The LHS matrix on the enforcement advice page (board DqHandhaving) is read
 * from the municipality's own lhsMatrix record, never from a table written
 * into the manifest: a fixed table would show one municipality's matrix on
 * every install and could disagree with the matrix the advice used.
 *
 * @spec openspec/specs/enforcement-advice/spec.md
 */

import { describe, expect, it } from 'vitest'
import nl from '../../l10n/nl.json'
import manifest from '../../src/manifest.json'

const page = manifest.pages.find((p) => p.id === 'LhsRecommendationDetail')
const matrix = page.config.widgets.find((w) => w.id === 'lhs-matrix')

describe('the LHS matrix on the enforcement advice', () => {
	it('reads the cells from the matrix record the advice names', () => {
		expect(matrix.content.chartKind).toBe('matrix')
		// A detail page hands the chart its content keys as props; the chart's kind prop is `type`.
		expect(matrix.content.type).toBe('matrix')
		const source = matrix.content.cellSource
		expect(source.schema).toBe('lhsMatrix')
		expect(source.filter).toEqual({ version: '@object.matrixVersion' })
		expect(source.cellFilter).toEqual({ actorType: '@object.actorType' })
		// Only the matrix the advice was calculated with, never a stand-in.
		expect(source.fallbackFilter).toBeUndefined()
		expect(matrix.content.series).toBeUndefined()
		expect(matrix.content.categories).toBeUndefined()
	})

	it('names rows, columns and cells where the chart reads them, inside cellSource', () => {
		// CnChartWidget reads rowLabels, columnLabels and valueLabels off the
		// cellSource; beside it they were ignored and the cells read
		// "last_under_penaltypayment" (DqHandhaving: "Last onder dwangsom").
		const source = matrix.content.cellSource
		expect(source.valueLabels.last_under_penaltypayment).toBe('Order subject to a penalty')
		expect(source.rowLabels.goedwillend).toBe('Behaviour 1: well-meaning')
		expect(source.columnLabels.ernstig).toBe('Consequence C: serious')
		for (const key of ['rowLabels', 'columnLabels', 'valueLabels']) {
			expect(matrix.content[key], key).toBeUndefined()
		}
	})

	it('numbers behaviour and consequence as DqHandhaving does', () => {
		const source = matrix.content.cellSource
		const dutch = (key) => nl.translations[key] || key
		expect(Object.values(source.rowLabels).map(dutch)).toEqual(['Gedrag 1: welwillend', 'Gedrag 2: onwetend', 'Gedrag 3: calculerend', 'Gedrag 4: crimineel'])
		expect(Object.values(source.columnLabels).map(dutch)).toEqual(['Gevolg A: gering', 'Gevolg B: aanzienlijk', 'Gevolg C: ernstig', 'Gevolg D: zeer ernstig'])
	})

	it('fits its card to the matrix, so no empty band sits above it (DqHandhaving)', () => {
		expect(page.config.layout.find((l) => l.widgetId === 'lhs-matrix').sizeToContent).toBe(true)
	})

	it('says the advice under the matrix in the board words (DqHandhaving)', () => {
		const notice = matrix.content.notice
		const dutch = (key) => nl.translations[key] || key
		const record = { recommendedIntervention: 'last_under_penaltypayment', matrixVersion: 2, behaviour: 'onverschillig', severity: 'ernstig' }
		const matrixRecord = { name: 'Handhavingsmatrix Zuiddrecht', version: 2 }
		const words = {}
		for (const [name, token] of Object.entries(notice.values)) {
			const raw = token.startsWith('@source.') ? matrixRecord[token.replace('@source.', '')] : record[token.replace('@object.', '')]
			const ref = notice.labels[name]
			const map = ref === 'values' ? matrix.content.cellSource.valueLabels : ref
			words[name] = map && map[raw] ? dutch(map[raw]) : String(raw)
		}
		const fill = (key) => dutch(key).replace(/\{(\w+)\}/g, (m, name) => words[name])
		expect(notice.tone).toBe('info')
		expect(fill(notice.lead)).toBe('Advies: last onder dwangsom')
		expect(fill(notice.text)).toBe('Uitkomst van de beslistabel Handhavingsmatrix Zuiddrecht versie 2 met gedrag 2 en gevolg C.')
	})

	it('gives the row labels the board\'s 170px column (DqHandhaving)', () => {
		expect(matrix.content.matrixLabelWidth).toBe(170)
	})

	it('outlines the cell of the advice and comes first on the page', () => {
		expect(matrix.content.highlight).toEqual({ row: '@object.behaviour', column: '@object.severity' })
		expect(page.config.layout[0].widgetId).toBe('lhs-matrix')
	})

	it('shows the violation from the inspection behind the advice', () => {
		const violation = page.config.widgets.find((w) => w.id === 'lhs-violation')
		expect(violation.content.source).toEqual({ register: 'dossiq', schema: 'inspectieRapport', id: '@object.inspection' })
		expect(page.config.layout.find((l) => l.widgetId === 'lhs-violation').gridY).toBe(6)
	})

	it('closes the violation card up under the matrix, both ending at their content', () => {
		expect(page.config.gridFloat).toBe(false)
		expect(page.config.layout.every((l) => l.sizeToContent === true)).toBe(true)
	})
})
