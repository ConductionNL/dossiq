/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case's advice requests draw their status in tone, as DqZaakBesluiten
 * does: an open request amber, a received answer green, an expired one red.
 *
 * @spec exclude board tone of the advice status pills (DqZaakBesluiten), configuration only
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const simple = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const caseDetail = simple.pages.find((entry) => entry.id === 'CaseDetail')
const advice = caseDetail.configAppend.widgets.find((widget) => widget.id === 'case-advice-requests')

describe('the advice request status pills', () => {
	it('draw each status in its tone', () => {
		expect(advice.content.pill.colorMap).toEqual({ requested: 'warning', received: 'success', expired: 'error' })
	})
})
