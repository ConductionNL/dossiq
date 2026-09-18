/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The incidents of a case, as the manifest lists them.
 *
 * 🔴 THE SORT IS THE WHOLE REQUIREMENT. A list ordered by the recording moment
 * renders, looks right, and puts the report written up three weeks late at the
 * top: the sequence the case is about is quietly rewritten and nothing says
 * so. So the sort field is asserted by name, and `recordedAt` is asserted to
 * be a COLUMN rather than the order.
 *
 * 🔴 AN INCIDENT IS NOT A DEELZAAK. The panel reads the `incident` schema and
 * not `case`, because a list pointed at cases would render sub-cases andeach one
 * would carry a beslistermijn it does not have.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)

const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
const widgets = caseDetail.config?.widgets ?? caseDetail.widgets ?? []
const workPanel = widgets.find((widget) => widget.id === 'case-work-panel')
const incidents = workPanel.content.sections.find(
	(section) => section.label === 'Incidents',
)?.widget

describe('the case lists its incidents', () => {
	it('has an incidents section on the Work tab', () => {
		expect(incidents, 'the Incidents section is missing').toBeTruthy()
		expect(incidents.id).toBe('case-incidents')
	})

	it('reads the incident schema, not cases', () => {
		expect(incidents.content.schema).toBe('incident')
		expect(incidents.content.filter).toEqual({ case: '@objectId' })
	})

	it('orders by when things happened, ascending', () => {
		// Not `recordedAt`, and not descending: the panel reads as the
		// sequence of events on the address.
		expect(incidents.content.sort).toEqual({ field: 'eventDate', dir: 'asc' })
	})

	it('shows the recording moment as a column, so the delay is visible', () => {
		const columns = incidents.content.columns.map((column) => column.key)
		expect(columns).toContain('eventDate')
		expect(columns).toContain('recordedAt')
	})

	it("shows the incident's own owner beside its state", () => {
		const columns = incidents.content.columns.map((column) => column.key)
		// The incident's assignee, not the case's: the case sits with the area
		// handler while one report is worked by an inspector.
		expect(columns).toContain('assignee')
		expect(columns).toContain('state')
	})

	it('keeps Tasks first on the tab it shares', () => {
		expect(workPanel.content.sections[0].label).toBe('Tasks')
	})

	it('says so when a case has none, rather than rendering an empty table', () => {
		expect(incidents.content.emptyText).toBeTruthy()
	})
})
