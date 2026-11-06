/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqTermijnen draws its widgets without an Actions menu; the page keeps
 * its own Actions menu, which carries refresh and documentation.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const page = manifest.pages.find((candidate) => candidate.id === 'TermijnDashboard')

describe('the term dashboard widgets', () => {
	it('draw no widget Actions menu, and neither does the page header', () => {
		expect(page.config.showWidgetActions).toBe(false)
		expect(page.config.showActionsMenu).toBe(false)
	})
})
