// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the UI calls and names says what it does. The old page address
 * redirects, and no frontend file calls a procedure-named URL any more (the
 * server keeps those routed for whoever else calls them).
 *
 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { RETIRED_ROUTES } from '../../src/utils/manifestRoutes.js'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Every file under a directory, recursively.
 *
 * @param {string} dir The directory.
 * @return {Array<string>} The file paths.
 */
function filesUnder(dir) {
	return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const full = path.join(dir, entry.name)
		return entry.isDirectory() ? filesUnder(full) : [full]
	})
}

describe('renamed UI names', () => {
	it('sends the earlier reports address to the document review reports page', () => {
		expect(RETIRED_ROUTES).toContainEqual({ path: '/woo-reports', redirect: { name: 'DocumentReviewReports' } })
		const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.d/86-document-review-reports.json'), 'utf8'))
		// The page is a library dashboard, so no app component renders it.
		expect(fragment.pages[0]).toMatchObject({ id: 'DocumentReviewReports', route: '/document-review-reports', type: 'dashboard' })
		expect(fragment.menu[0]).toMatchObject({ id: 'DocumentReviewReportsMenu', route: 'DocumentReviewReports' })
		expect(fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')).not.toContain('WooReportsView')
	})

	it('calls no procedure-named URL from the frontend', () => {
		const old = /api\/vth\/(checklists|cases\/[^'"`]*\/inspection-result)|api\/termijn\/(reports|dashboard|definities|calendar|instances)|api\/woo\/reports/
		const offenders = filesUnder(path.join(ROOT, 'src'))
			.filter((file) => /\.(js|vue|json)$/.test(file))
			.filter((file) => old.test(fs.readFileSync(file, 'utf8')))
			.map((file) => path.relative(ROOT, file))
		expect(offenders).toEqual([])
	})

	it('reads the deadline reports by their generic keys', () => {
		const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
		const page = manifest.pages.find((p) => p.id === 'TermijnDashboard')
		const json = JSON.stringify(page.config)
		expect(json).not.toMatch(/binnenTermijnPct|gemiddeldeDoorlooptijdDagen|withinTermijnPercent|"periode"|"jaar"|\{jaar\}/)
		expect(json).toContain('/apps/dossiq/api/deadlines/reports/quarterly')
	})
})
