/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the case shows about when its clock started.
 *
 * 🔴 A FIELD NOT IN AN `include` LIST RENDERS NOWHERE, SILENTLY. A data widget
 * builds its fields from that list, so a property declared on the schema and
 * missing here is stored, correct and invisible: the citizen is told in the
 * mail and the handler answering their phone call is not.
 *
 * 🔴 THE THREE ARE READ-ONLY ON THE SCHEMA, so nobody edits the answer the
 * citizen was given. That is asserted against the register rather than
 * remembered, because an editable stamp is a stamp that stops being evidence.
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const register = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'), 'utf8'))

const caseProps = register.components.schemas.case.properties
const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
const widgets = caseDetail.config?.widgets ?? caseDetail.widgets ?? []
const terms = widgets.find((widget) => widget.id === 'case-terms')

describe('the case says when it arrived and when its clock started', () => {
	it.each(['receivedAt', 'termStartsAt', 'receivedOutsideWorkingHours'])('declares %s on the case', (field) => {
		expect(caseProps[field], `case.${field} is missing`).toBeTruthy()
	})

	it('shows all three on the terms panel, where a handler answers the question', () => {
		for (const field of ['receivedAt', 'termStartsAt', 'receivedOutsideWorkingHours']) {
			expect(terms.content.include, `${field} is stored and rendered nowhere`).toContain(field)
		}
	})

	it('keeps the deadline beside them, so the four facts read together', () => {
		expect(terms.content.include).toContain('statutoryTerm')
	})

	it('makes the stamp read-only, because an editable one stops being evidence', () => {
		for (const field of ['receivedAt', 'termStartsAt', 'receivedOutsideWorkingHours']) {
			expect(caseProps[field].readOnly).toBe(true)
		}
	})

	it('stores both moments as moments, not as dates', () => {
		// A date loses the evening, and the evening is the whole point: a
		// filing at 20:41 on Sunday and one at 09:00 on Monday are different
		// facts that a `date` format renders identical.
		expect(caseProps.receivedAt.format).toBe('date-time')
		expect(caseProps.termStartsAt.format).toBe('date-time')
	})

	it('defaults the flag to false, so an unstamped case claims nothing', () => {
		expect(caseProps.receivedOutsideWorkingHours.default).toBe(false)
	})
})
