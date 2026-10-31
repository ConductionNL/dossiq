// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A move in the lifecycle menu says where it goes, as board DqVolgendeStap
 * draws it: "Naar Besluit". A move whose label is just its status's name
 * reads "To {status}"; a move an administrator gave a label of its own keeps
 * that label. Nothing here names a procedure: the status name is data.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import { buildActsMenu } from '../../src/utils/caseActsMenu.js'

vi.mock('@nextcloud/dialogs', () => ({ showWarning: vi.fn(), showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const CaseLifecycleMenuDialog = (await import('../../src/dialogs/CaseLifecycleMenuDialog.vue')).default
const nl = (await import('../../l10n/nl.json')).default.translations
const { label } = CaseLifecycleMenuDialog.methods

/**
 * The menu entry of one move.
 *
 * @param {object} row The `/available-transitions` row.
 * @return {object} The entry.
 */
const moveEntry = (row) => buildActsMenu({ transitions: [row], state: {}, acts: { acts: [] } })[0]

describe('a move in the lifecycle menu', () => {
	it('carries the name of the status it leads to', () => {
		expect(moveEntry({ id: 't-1', label: 'Besluit', toStatus: 'st-3', toStatusName: 'Besluit' }).toStatusName).toBe('Besluit')
	})

	it('reads "To {status}" when its label is the status name, or when it has none', () => {
		expect(label(moveEntry({ id: 't-1', label: 'Besluit', toStatus: 'st-3', toStatusName: 'Besluit' }))).toBe('To Besluit')
		expect(label(moveEntry({ id: 't-1', label: '', toStatus: 'st-3', toStatusName: 'Besluit' }))).toBe('To Besluit')
		expect(nl['To {status}']).toBe('Naar {status}')
	})

	it('keeps a label an administrator wrote, and a move without a status name as it was', () => {
		expect(label(moveEntry({ id: 't-1', label: 'Terug naar de aanvrager', toStatus: 'st-1', toStatusName: 'Ontvangen' }))).toBe('Terug naar de aanvrager')
		expect(label(moveEntry({ id: 't-1', label: 'Besluitvorming', toStatus: 'st-3' }))).toBe('Besluitvorming')
	})
})
