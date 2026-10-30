// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The reason fields as the dialog boards draw them: suspend asks a one-line
 * reason beside the days (DqVolgendeStap), the other acts a text area, and the
 * Woo decision's reasons are four lines (DqBesluit).
 *
 * @spec openspec/changes/archive/2026-10-30-dialog-board-fields/specs/dialog-board-fields/spec.md#requirement-a-dialog-s-reason-field-has-the-board-s-height
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { actForm } from '../../src/utils/caseActInputs.js'

/**
 * The reason input of one act's form.
 *
 * @param {string} id The act id.
 * @return {object} The declaration.
 */
function reasonOf(id) {
	return actForm({ id }).inputs.find((input) => input.field === 'reason')
}

describe('dialog reason fields', () => {
	it('asks a one-line reason beside the days when suspending', () => {
		expect(reasonOf('suspend')).toMatchObject({ widget: 'text', id: 'case-act-reason', testid: 'case-act-reason-input' })
		expect(actForm({ id: 'suspend' }).columns).toBe(2)
	})

	it('keeps a text area for an act without days', () => {
		expect(reasonOf('abort')).toMatchObject({ widget: 'textarea', id: 'case-act-reason', testid: 'case-act-reason-input' })
		expect(actForm({ id: 'abort' }).columns).toBe(1)
	})

	it('gives the Woo decision\'s reasons four lines', () => {
		const sfc = fs.readFileSync(path.resolve(__dirname, '../../src/dialogs/AssessmentDecisionDialog.vue'), 'utf8')
		const area = sfc.match(/<NcTextArea[^>]*id="assessment-decision-reasons"[^>]*\/>/)
		expect(area).not.toBeNull()
		expect(area[0]).toContain(':rows="4"')
	})
})
