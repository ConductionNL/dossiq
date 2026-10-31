/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lead-time dashboard's severity pills follow DqDoorlooptijd: overdue is
 * red, critical and at risk are amber, on track is green.
 *
 * @spec exclude board tone of the severity pills (DqDoorlooptijd), configuration only
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((entry) => entry.id === 'Doorlooptijd')
const severityColumns = page.config.widgets
	.flatMap((widget) => (widget.content && widget.content.columns) || [])
	.filter((column) => column.key === 'severity')

describe('the lead-time severity pills', () => {
	it('are found on both deadline tables', () => {
		expect(severityColumns).toHaveLength(2)
	})

	it.each([0, 1])('table %i draws critical amber and overdue red', (index) => {
		expect(severityColumns[index].colorMap).toEqual({ overdue: 'error', critical: 'warning', warning: 'warning', ok: 'success' })
	})
})
