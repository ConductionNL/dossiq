// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the lifecycle menu's `label` and `explainer`
 * (src/dialogs/CaseLifecycleMenuDialog.vue): the translated sentence per act,
 * the transition and remedy kinds, the extend variants and the fallbacks.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (app, text, vars) => '[' + app + ':' + text + (vars ? ':' + JSON.stringify(vars) : '') + ']',
}))
vi.mock('@nextcloud/dialogs', () => ({ showWarning: vi.fn(), showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const { methods } = (await import('../../src/dialogs/CaseLifecycleMenuDialog.vue')).default

describe('lifecycle menu label', () => {
	it.each([
		['suspend', 'Suspend'],
		['resume', 'Resume'],
		['extend', 'Extend term'],
		['reopen', 'Reopen or restore from the archive'],
		['finish', 'Finish'],
		['abort', 'Break off'],
		['archive', 'Archive it'],
		['unarchive', 'Reopen or restore from the archive'],
		['hold', 'Hold'],
		['release-hold', 'Take off hold'],
		['promote', 'Promote to case'],
	])('translates the %s act', (id, text) => {
		expect(methods.label({ id, label: 'ignored' })).toBe('[dossiq:' + text + ']')
	})

	it('names a transition after its target status', () => {
		expect(methods.label({ id: 't1', kind: 'transition', toStatusName: 'Besluit' })).toBe('[dossiq:To {status}:{"status":"Besluit"}]')
		expect(methods.label({ id: 't1', kind: 'transition', label: 'Besluit', toStatusName: 'Besluit' })).toBe('[dossiq:To {status}:{"status":"Besluit"}]')
	})

	it('passes an authored label through', () => {
		expect(methods.label({ id: 't1', kind: 'transition', label: 'Naar besluit', toStatusName: 'Besluit' })).toBe('Naar besluit')
		expect(methods.label({ id: 'toString', label: 'Own' })).toBe('Own')
		expect(methods.label(null)).toBe('')
	})
})

describe('lifecycle menu explainer', () => {
	it.each([
		['finish', 'Choose the result type. The end date and the archive outcome are recorded.'],
		['suspend', 'The term stands still until the answer is in.'],
		['abort', 'The case stops without a decision. Asks for a reason.'],
		['archive', 'The case moves to the retention rule its result type carries.'],
		['reopen', 'Only for a finished or archived case.'],
		['unarchive', 'Only for a finished or archived case.'],
		['hold', 'Park it internally, the term keeps running.'],
		['release-hold', 'You pick the case up again before its date.'],
		['promote', 'The term starts now. The case leaves your drafts.'],
	])('translates the %s explainer', (id, text) => {
		expect(methods.explainer({ id, explainer: 'ignored' })).toBe('[dossiq:' + text + ']')
	})

	it('picks the extend sentence the menu chose', () => {
		expect(methods.explainer({ id: 'extend', explainer: 'Once, with a reason. The requester gets a message.' }))
			.toBe('[dossiq:Once, with a reason. The requester gets a message.]')
		expect(methods.explainer({ id: 'extend', explainer: 'anything else' }))
			.toBe('[dossiq:With a reason. The requester gets a message.]')
		expect(methods.explainer({ id: 'extend' }))
			.toBe('[dossiq:With a reason. The requester gets a message.]')
	})

	it('explains a transition and passes a remedy explainer through', () => {
		expect(methods.explainer({ id: 'finish', kind: 'transition' }))
			.toBe('[dossiq:The next status of the case type. Statuses that cannot follow from here are greyed out.]')
		expect(methods.explainer({ id: 'finish', kind: 'remedy', explainer: 'Own words' })).toBe('Own words')
		expect(methods.explainer({ id: 'finish', kind: 'remedy' })).toBe('')
	})

	it.each([null, {}, { id: 'resume' }, { id: 'toString' }, { id: 'unknown' }])('answers empty for %j', (entry) => {
		expect(methods.explainer(entry)).toBe('')
	})
})
