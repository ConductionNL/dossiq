// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Process analytics shows dwell time as a heatmap: statuses against weeks,
 * shaded by median dwell, each cell naming its value (REQ-CRS-36, row 10.4).
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/process-mining-bottlenecks/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const page = manifest.pages.find((entry) => entry.id === 'ProcessMiningDashboard').config
const widget = page.widgets.find((entry) => entry.id === 'pm-dwell-heatmap')
const views = fs.readFileSync(path.join(ROOT, 'lib/Service/ProcessMining/ReportViews.php'), 'utf8')

describe('the dwell time heatmap', () => {
	it('is a matrix of statuses against weeks read from the heatmap view', () => {
		expect(widget.type).toBe('chart')
		expect(widget.content.chartKind).toBe('matrix')
		expect(widget.content.endpointSource).toMatchObject({
			url: '/apps/dossiq/api/reports/process-mining',
			params: { view: 'heatmap', period: '@workspace.period?', caseType: '@workspace.caseType?' },
			responsePath: 'heatmap',
			labelsPath: 'columns',
			rowsPath: 'rows',
			rowLabelPath: 'label',
			rowValuesPath: 'values',
		})
	})

	it('prints each cell as hours, so a cell names its value', () => {
		expect(widget.content.valueFormat).toEqual({ name: 'number', decimals: 1, suffix: ' h' })
	})

	it('is a view the report endpoint accepts', () => {
		expect(views).toMatch(/public const VIEWS = \[[^\]]*'heatmap'/)
	})

	it('is placed on the page and declared for the same audience as the dwell chart', () => {
		const dwell = page.widgets.find((entry) => entry.id === 'pm-dwell')
		expect(widget.roles).toEqual(dwell.roles)
		expect(widget.visibleWhen.field).toBe('visible.pm-dwell-heatmap')
		const placed = page.layout.find((item) => item.widgetId === 'pm-dwell-heatmap')
		expect(placed).toMatchObject({ gridX: 0, gridWidth: 12 })
		const ids = page.layout.map((item) => item.id)
		expect(new Set(ids).size).toBe(ids.length)
	})
})
