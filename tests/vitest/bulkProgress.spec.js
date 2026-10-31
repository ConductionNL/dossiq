/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a bulk act over cases says while it runs.
 *
 * The panel itself (the skip list, skipped and refused kept apart, the commit
 * that is the only write, the polling) is the library's CnBulkJobProgress and
 * is tested there, in tests/components/CnBulkJobProgress.spec.js. What dossiq
 * owns is the WORDING: a handler reads "cases", not "items", and the Dutch
 * dossiq already had. Both bulk dialogs must hand those labels over.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */

import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { caseJobLabels } from '../../src/utils/selectionScope.js'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Fill a label template the way CnBulkJobProgress does.
 *
 * @param {string} template The template.
 * @param {object} vars The values.
 * @return {string} The sentence.
 */
function fill(template, vars) {
	return template.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match))
}

describe('the case wording of a bulk job', () => {
	const labels = caseJobLabels()

	it('says nothing has been written yet while the job is only rehearsed', () => {
		expect(fill(labels.statePreviewed, { total: 400 })).toBe(
			'Nothing has been written yet. This is what would happen to 400 cases.',
		)
		expect(fill(labels.commit, { total: 400 })).toBe('Apply to 400 cases')
	})

	it('counts cases while it runs and after it stopped', () => {
		expect(fill(labels.stateRunning, { processed: 120, total: 400 })).toBe(
			'Running. 120 of 400 cases done.',
		)
		expect(fill(labels.stateCancelled, { processed: 120, total: 400 })).toBe(
			'Stopped. 120 of 400 cases were done first.',
		)
	})

	it('leaves the tokens for the component to fill', () => {
		expect(labels.stateCompleted).toContain('{processed}')
		expect(labels.stateCompleted).toContain('{total}')
	})
})

describe('the bulk dialogs mount the library panel with the case wording', () => {
	for (const file of ['BulkTransitionDialog.vue', 'ReassignSelectionDialog.vue']) {
		it(file, () => {
			const source = fs.readFileSync(path.join(ROOT, 'src', 'dialogs', file), 'utf8')

			expect(source).toMatch(/<CnBulkJobProgress[^>]*:labels="jobLabels"/)
			expect(source).toMatch(/<CnSelectionScope[^>]*:labels="scopeLabels"/)
			expect(source).toContain('jobLabels: caseJobLabels()')
			expect(source).not.toContain('components/bulk/')
		})
	}
})
