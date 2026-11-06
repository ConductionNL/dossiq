/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTermijnen "Op tijd beslist per zaaktype" and DqDoorlooptijd "Binnen de
 * norm per zaaktype" draw the wide share list (220px labels, a 90px value
 * column, 12px bars 12px apart); the dashboards' status and type counts keep
 * the compact list.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */

import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

const chart = (pageId, id) => manifest.pages.find((page) => page.id === pageId).config.widgets.find((widget) => widget.id === id).content

describe('share lists', () => {
	it('draws the on-time and within-norm shares wide', () => {
		expect(chart('TermijnDashboard', 'deadline-report-on-time-by-type').barListWide).toBe(true)
		expect(chart('Doorlooptijd', 'processing-time-within-by-type').barListWide).toBe(true)
	})

	it('keeps the dashboard counts compact', () => {
		expect(chart('Dashboard', 'cases-by-status').barListWide).toBeUndefined()
	})
})
