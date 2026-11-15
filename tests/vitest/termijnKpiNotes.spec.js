/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqTermijnen: the "Verloopt deze week" tile says which case type most
 * of those terms belong to ("3 bij Woo-verzoeken"), the "Dwangsom loopt" tile
 * how far the longest running penalty is ("1 zaak, dag 9 van 42"). The
 * server words both notes (dueThisWeekNote, penaltyRunningNote).
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */

import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

const page = manifest.pages.find((item) => item.id === 'TermijnDashboard')
const tile = (id) => page.config.widgets.find((item) => item.id === id).content

describe('deadline KPI notes', () => {
	it('names the lead case type under the terms ending this week', () => {
		expect(tile('deadline-report-due-week').caption).toBe('{dueThisWeekNote}')
	})

	it('names the day of the running penalty', () => {
		expect(tile('deadline-report-penalty').caption).toBe('{penaltyRunningNote}')
		expect(tile('deadline-report-penalty').captionVariant).toBe('error')
	})
})
