/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tasks list opens on this month's deadlines, as board DqTaken draws it:
 * "Deadline: 1 tot en met 31 oktober", a chip the reader can remove.
 *
 * @spec openspec/specs/task-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

const config = manifest.pages.find((page) => page.id === 'Tasks').config

describe('the tasks list opens on this month', () => {
	it('starts with the due range from the first to the last day of the month', () => {
		expect(config.defaultFilters).toEqual({ dueAt: { from: '@monthStart', to: '@monthEnd' } })
	})

	it('filters a field the sidebar declares as a date range', () => {
		expect(config.sidebar.fields.dueAt).toMatchObject({ facetable: true, inputControl: 'date-range' })
	})
})
