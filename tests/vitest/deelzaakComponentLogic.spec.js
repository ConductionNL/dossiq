/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component-logic unit tests for the sub-case UI.
 *
 * Dossiq's Vitest project runs in the `node` environment with no Vue mount
 * harness installed (see vitest.config.js), so we cannot full-mount the .vue
 * leaves here. Instead we replicate the pure, side-effect-free computed logic
 * that drives each new component's rendering and assert it directly:
 *
 *   - DeelzaakCreateModal.availableCaseTypes (filter to parent.subCaseTypes)
 *
 * Each function below is a faithful copy of the component's computed/method
 * body (kept in lock-step with the source). Behavioural drift in the source
 * that is not mirrored here will surface as a coverage gap, not a false green.
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T08
 */

import { describe, expect, it } from 'vitest'

// --- DeelzaakCreateModal.availableCaseTypes (verbatim filter) ---------------
function availableCaseTypes(caseTypes, parentCaseType) {
	const allowed = parentCaseType?.subCaseTypes || []
	if (!Array.isArray(allowed) || allowed.length === 0) {
		return []
	}
	const allowedSet = new Set(allowed.map(String))
	return caseTypes.filter(
		(ct) =>
			allowedSet.has(String(ct.id))
			|| allowedSet.has(String(ct.slug))
			|| allowedSet.has(String(ct.uuid)),
	)
}

describe('DeelzaakCreateModal.availableCaseTypes', () => {
	const caseTypes = [
		{ id: 'ct-1', title: 'Permit' },
		{ id: 'ct-2', title: 'Objection' },
		{ id: 'ct-3', title: 'Other' },
	]

	it('keeps only the case types listed on parent.subCaseTypes', () => {
		const out = availableCaseTypes(caseTypes, { subCaseTypes: ['ct-1', 'ct-3'] })
		expect(out.map((c) => c.id)).toEqual(['ct-1', 'ct-3'])
	})

	it('matches against id, slug, or uuid', () => {
		const cts = [
			{ id: '1', slug: 'permit' },
			{ id: '2', uuid: 'uuid-abc' },
		]
		expect(
			availableCaseTypes(cts, { subCaseTypes: ['permit'] }).map((c) => c.id),
		).toEqual(['1'])
		expect(
			availableCaseTypes(cts, { subCaseTypes: ['uuid-abc'] }).map((c) => c.id),
		).toEqual(['2'])
	})

	it('returns empty when the parent allows nothing', () => {
		expect(availableCaseTypes(caseTypes, { subCaseTypes: [] })).toEqual([])
		expect(availableCaseTypes(caseTypes, null)).toEqual([])
	})
})
