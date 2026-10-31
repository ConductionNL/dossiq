/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case's contact moments draw an outgoing moment in the blue tone and an
 * incoming one neutral, as DqZaakContact does.
 *
 * @spec exclude board tone of the direction pills (DqZaakContact), configuration only
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const simple = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const caseDetail = simple.pages.find((entry) => entry.id === 'CaseDetail')
const moments = caseDetail.configAppend.widgets.find((widget) => widget.id === 'case-contact-moments-card')

describe('the contact moment direction pills', () => {
	it('draw outgoing blue and incoming neutral', () => {
		const direction = moments.content.columns.find((column) => column.key === 'direction')
		expect(direction.colorMap).toEqual({ inbound: 'default', outbound: 'info' })
	})
})
