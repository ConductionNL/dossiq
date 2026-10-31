/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqDoorlooptijd's "Downloaden": a header action that GETs the metrics
 * endpoint as CSV with the period and case type the page shows.
 *
 * @spec openspec/specs/doorlooptijd-csv/spec.md#requirement-the-processing-time-figures-download-as-csv
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const page = manifest.pages.find((item) => item.id === 'Doorlooptijd')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')

describe('the Doorlooptijd download', () => {
	it('GETs the metrics endpoint as CSV with the page\'s period and case type', () => {
		const action = page.config.headerActions.find((item) => item.id === 'download-processing-time')
		expect(action).toMatchObject({
			type: 'api-call',
			method: 'GET',
			url: '/apps/dossiq/api/doorlooptijd/metrics',
			params: { format: 'csv', period: '@workspace.datePreset?', caseType: '@workspace.caseType?' },
			download: true,
			label: 'Download',
		})
	})

	it('reads an endpoint the app routes', () => {
		expect(routes).toContain("'url' => '/api/doorlooptijd/metrics', 'verb' => 'GET'")
	})
})
