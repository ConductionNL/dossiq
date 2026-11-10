/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation golden for `actForm` (src/utils/caseActInputs.js): the
 * whole form every act opens, for each scope that changes it. The snapshot
 * was recorded on the code before the form was split into input builders.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import { actForm } from '../../src/utils/caseActInputs.js'

const ENTRIES = [
	null,
	{ id: 'suspend' },
	{ id: 'resume' },
	{ id: 'extend' },
	{ id: 'reopen' },
	{ id: 'unarchive' },
	{ id: 'finish' },
	{ id: 'abort' },
	{ id: 'archive' },
	{ id: 'hold' },
	{ id: 'release-hold' },
	{ id: 'promote' },
	{ id: 't-1', kind: 'transition' },
	{ id: 't-2', kind: 'transition', closesCase: true, resultTypes: [{ id: 'r' }] },
	{ id: 't-3', kind: 'transition', closesCase: true, resultTypes: [] },
	{ kind: 'gesture' },
]

const SCOPES = [
	undefined,
	{ caseType: 'ct-1', caseId: 'case-1', requester: 'Jan Jansen', reminder: { afterDays: 7, maxCount: 2 } },
	{ requester: '', reminder: { afterDays: '3', maxCount: 0 } },
	{ reminder: { afterDays: 0, maxCount: 1 } },
	{ reminder: null },
]

describe('actForm golden', () => {
	it.each(ENTRIES.map((entry) => [JSON.stringify(entry), entry]))('opens the recorded form for %s', (_label, entry) => {
		expect(SCOPES.map((scope) => actForm(entry, scope))).toMatchSnapshot()
	})
})
