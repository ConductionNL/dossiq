// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The reason fields as the dialog boards draw them: suspend asks a one-line
 * reason beside the days (DqVolgendeStap), the other acts a text area, and the
 * Woo decision's reasons are four lines (DqBesluit).
 *
 * @spec openspec/changes/dialog-board-fields/specs/dialog-board-fields/spec.md#requirement-a-dialog-s-reason-field-has-the-board-s-height
 */

import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import { inputsFor } from '../../src/utils/caseActsMenu.js'

vi.mock('@nextcloud/dialogs', () => ({ showWarning: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const CaseActFields = (await import('../../src/components/case/CaseActFields.vue')).default

const stubs = {
	NcTextField: { name: 'NcTextField', props: ['modelValue', 'label'], template: '<input class="text-field" :value="modelValue">' },
	NcTextArea: { name: 'NcTextArea', props: ['modelValue', 'label'], template: '<textarea class="text-area" :value="modelValue" />' },
	NcSelect: true,
	NcNoteCard: true,
	CnTemplateSelect: true,
	FolderOutline: true,
}

/**
 * Mount the fields for one act.
 *
 * @param {string} id The act id.
 * @return {object} The wrapper.
 */
function fieldsFor(id) {
	const act = { id }
	return mount(CaseActFields, { props: { act, inputs: inputsFor(act) }, global: { stubs } })
}

describe('dialog reason fields', () => {
	it('asks a one-line reason beside the days when suspending', () => {
		const w = fieldsFor('suspend')
		expect(w.find('.text-field#case-act-reason').exists()).toBe(true)
		expect(w.find('.text-area').exists()).toBe(false)
	})

	it('keeps a text area for an act without days', () => {
		const w = fieldsFor('abort')
		expect(w.find('.text-area#case-act-reason').exists()).toBe(true)
	})

	it('gives the Woo decision\'s reasons four lines', () => {
		const sfc = fs.readFileSync(path.resolve(__dirname, '../../src/dialogs/AssessmentDecisionDialog.vue'), 'utf8')
		const area = sfc.match(/<NcTextArea[^>]*id="assessment-decision-reasons"[^>]*\/>/)
		expect(area).not.toBeNull()
		expect(area[0]).toContain(':rows="4"')
	})
})
