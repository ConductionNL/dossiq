/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One announcement that cases changed outside the list showing them, heard by
 * every list that shows cases: the app's own listeners, an index page
 * (CnIndexPage listens for `cn:objects-changed`) and a dashboard list widget
 * (CnObjectListWidget listens on the `cn:page:refresh` bus channel). A claimed
 * case that stayed on the queue invited a second claim on a case that
 * already moved.
 *
 * @spec openspec/changes/a-handling-team-opens-its-unclaimed-cases/specs/case-management/spec.md#requirement-a-case-that-changes-hands-leaves-every-list-showing-it-at-once-req-cm-claim-02
 */

import { dispatchObjectsChanged } from '@conduction/nextcloud-vue'
import { emit } from '@nextcloud/event-bus'

export const CASES_CHANGED = 'dossiq:cases-changed'

/**
 * Tell every list showing cases to read them again.
 *
 * @param {string} [caseId] The case that changed, when it is one.
 * @return {void}
 *
 * @spec openspec/changes/a-handling-team-opens-its-unclaimed-cases/specs/case-management/spec.md#requirement-a-case-that-changes-hands-leaves-every-list-showing-it-at-once-req-cm-claim-02
 */
export function announceCasesChanged(caseId) {
	window.dispatchEvent(new CustomEvent(CASES_CHANGED))
	dispatchObjectsChanged({ register: 'dossiq', schema: 'case', id: caseId || null })
	emit('cn:page:refresh')
}
