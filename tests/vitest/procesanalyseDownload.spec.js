/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqProcesanalyse's "Downloaden": a header action that GETs the process-mining
 * report as CSV with the statistic and case type the page shows.
 *
 * @spec openspec/specs/procesanalyse-csv/spec.md#requirement-the-days-per-phase-download-as-csv
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const page = manifest.pages.find((item) => item.id === 'ProcessMiningDashboard')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')

describe('the Procesanalyse download', () => {
	it('GETs the report as CSV with the page\'s statistic and case type', () => {
		const action = (page.config.headerActions || []).find((item) => item.id === 'download-process-analysis')
		expect(action).toMatchObject({
			type: 'api-call',
			method: 'GET',
			url: '/apps/dossiq/api/reports/process-mining',
			params: { format: 'csv', statistic: '@workspace.statistic?', caseType: '@workspace.caseType?' },
			download: true,
			label: 'Download',
		})
	})

	it('reads an endpoint the app routes', () => {
		expect(routes).toContain("'url' => '/api/reports/process-mining', 'verb' => 'GET'")
	})
})
