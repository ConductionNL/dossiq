/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakDocumenten draws each document on two lines: the name, and under it
 * its type, size and date ("PDF · 1,2 MB · 14 januari 2026").
 */

import { describe, expect, it } from 'vitest'
const panels = require('./helpers/casePanels.js')

describe('the case documents rows', () => {
	it('carry the type, size and date under the name', () => {
		expect(panels.caseWidget('case-files').props.nameSecondary).toBe('{type} · {size} · {modified|date}')
	})
})
