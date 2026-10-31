// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqVolgendeStap draws ONE entry, "Heropenen of terughalen uit het
 * archief", where the menu had two: Reopen (a term gesture) and Restore from
 * the archive (shown only on an archived case). The menu now shows one entry
 * that carries the act the case allows: Restore on an archived case, because
 * an archived case refuses every write until it is back; Reopen otherwise.
 * Refused, it says what the board says.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import { buildActsMenu } from '../../src/utils/caseActsMenu.js'

vi.mock('@nextcloud/dialogs', () => ({ showWarning: vi.fn(), showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const CaseLifecycleMenuDialog = (await import('../../src/dialogs/CaseLifecycleMenuDialog.vue')).default
const nl = (await import('../../l10n/nl.json')).default.translations

const BOARD = 'Only for a finished or archived case.'
const LABEL = 'Reopen or restore from the archive'

/** The archive verdict a handler with the archivaris role gets. */
const ARCHIVE_ALLOWED = { act: 'archive', allowed: true, role: 'archivaris', reason: '' }

/**
 * The entries that reopen or restore, in menu order.
 *
 * @param {object} state The `/lifecycle` answer.
 * @param {object} acts The `/acts` answer.
 * @return {Array<object>} The entries.
 */
function backEntries(state, acts) {
	return buildActsMenu({ transitions: [], state, acts }).filter((row) => row.id === 'reopen' || row.id === 'unarchive')
}

describe('Reopen or restore from the archive', () => {
	it('is one refused entry on an open case, with the board\'s sentence', () => {
		const rows = backEntries({ canReopen: false }, { acts: [ARCHIVE_ALLOWED] })
		expect(rows).toHaveLength(1)
		expect(rows[0]).toMatchObject({ kind: 'gesture', id: 'reopen', label: LABEL, disabled: true, reason: BOARD, explainer: BOARD })
	})

	it('reopens a closed case that is not archived', () => {
		const rows = backEntries({ canReopen: true }, { acts: [ARCHIVE_ALLOWED] })
		expect(rows).toHaveLength(1)
		expect(rows[0]).toMatchObject({ kind: 'gesture', id: 'reopen', label: LABEL, disabled: false })
	})

	it('restores an archived case first, under the archive role, and offers no second entry', () => {
		const rows = backEntries({ canReopen: true }, { archived: true, acts: [ARCHIVE_ALLOWED] })
		expect(rows).toHaveLength(1)
		expect(rows[0]).toMatchObject({ kind: 'state', id: 'unarchive', label: LABEL, disabled: false, explainer: BOARD })

		const refused = backEntries({ canReopen: true }, {
			archived: true,
			acts: [{ act: 'archive', allowed: false, role: 'archivaris', reason: 'This act needs the archivaris group.' }],
		})
		expect(refused).toHaveLength(1)
		expect(refused[0]).toMatchObject({ id: 'unarchive', disabled: true, reason: 'This act needs the archivaris group.' })
	})

	it('reads the same in the dialog for either act, in Dutch as the board does', () => {
		const { label, explainer } = CaseLifecycleMenuDialog.methods
		for (const id of ['reopen', 'unarchive']) {
			expect(label({ id }), id).toBe(LABEL)
			expect(explainer({ id }), id).toBe(BOARD)
		}
		expect(nl[LABEL]).toBe('Heropenen of terughalen uit het archief')
		expect(nl[BOARD]).toBe('Alleen bij een afgeronde of gearchiveerde zaak.')
	})
})
