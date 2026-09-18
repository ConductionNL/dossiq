// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The split picker, and the action that opens it.
 *
 * This is the surface `splitting-a-case-and-its-incidents` shipped without and
 * said so: the rules were merged complete and reachable from nothing. Each
 * assertion below guards a way the surface could ship dark in the same way:
 *
 *  - an `open-modal` action whose target the registry does not answer renders
 *    nothing at all, with no warning and no console error, which is exactly
 *    why that change declined to declare it early;
 *  - a registry-mounted modal whose `open` prop defaults false renders no
 *    markup and never warns;
 *  - an icon that is not registered renders no glyph, so the action reads as a
 *    gap in the row;
 *  - the dialog GETs and POSTs `/api/case/{id}/split`, so both verbs are
 *    asserted to have a route, because a picker that cannot read what may be
 *    divided offers nothing and looks like a case with nothing on it;
 *  - the picker must ask the SERVER what may be divided rather than listing
 *    the three parts itself: a checkbox for a part the case type forbids is a
 *    checkbox that wastes a split.
 *
 * @spec openspec/changes/case-split-surface/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(
	path.join(ROOT, 'src', 'registry.js'),
	'utf8',
)
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')
const dialog = fs.readFileSync(
	path.join(ROOT, 'src', 'dialogs', 'CaseSplitDialog.vue'),
	'utf8',
)

const casePage = manifest.pages.find((p) => p.id === 'CaseDetail')
/**
 * The split action on the case page.
 *
 * @return {object} The action.
 */
function action() {
	return casePage.config.headerActions.find((a) => a.id === 'case-split')
}

describe('Split in two', () => {
	it('exists at last, and opens a modal the registry holds', () => {
		expect(action(), 'no split action on the case page').toBeTruthy()
		expect(action().type).toBe('open-modal')
		expect(action().target).toBe('CaseSplitDialog')
		expect(registrySource).toContain('CaseSplitDialog: {')
		expect(registrySource).toContain(
			"import CaseSplitDialog from './dialogs/CaseSplitDialog.vue'",
		)
	})

	it('passes the open prop, without which the modal renders nothing', () => {
		expect(action().props.open).toBe(true)
		expect(action().props.caseId).toBe('@objectId')
	})

	it('uses an icon that is registered', () => {
		expect(iconsSource).toContain(
			`import ${action().icon} from 'vue-material-design-icons/${action().icon}.vue'`,
		)
		expect(iconsSource).toContain(`\t${action().icon},`)
	})

	it('sits beside the merge, because they are inverses', () => {
		const ids = casePage.config.headerActions.map((a) => a.id)

		expect(ids).toContain('case-merge')
		expect(Math.abs(ids.indexOf('case-split') - ids.indexOf('case-merge'))).toBe(1)
	})

	it('is hidden on a closed case and on nothing else', () => {
		expect(action().visibleWhen).toEqual({
			field: 'isFinalStatus',
			op: 'neq',
			value: true,
		})
	})
})

describe('The picker', () => {
	it('asks the server what may be divided, rather than listing the parts itself', () => {
		expect(dialog).toContain(
			'/apps/dossiq/api/case/${encodeURIComponent(this.targetCaseId)}/split',
		)
		expect(dialog).toContain('this.allowed = data?.allowed || []')
		// The three parts appear only as LABELS for what the server allowed.
		expect(dialog).toContain('return this.allowed.map((id) =>')
	})

	it('has a route behind both verbs', () => {
		expect(routes).toContain("'name' => 'caseSplit#divisible'")
		expect(routes).toContain("'name' => 'caseSplit#split'")
		expect(routes).toContain("'url' => '/api/case/{caseId}/split', 'verb' => 'GET'")
		expect(routes).toContain("'url' => '/api/case/{caseId}/split', 'verb' => 'POST'")
	})

	it('ticks nothing to begin with, because a split cannot be unticked after', () => {
		expect(dialog).toContain('chosen: { documents: [], parties: [], tasks: [] }')
	})

	it('offers no control for a row on both halves, which the plan cannot do', () => {
		// An earlier draft had one. `CaseSplitPlan` repoints a row and has no
		// notion of one on both cases, so the switch would have done nothing.
		expect(dialog).not.toContain('sharedParties')
		expect(dialog).not.toContain('toggleShared')
	})

	it('shows the refusal the server wrote, rather than one of its own', () => {
		expect(dialog).toContain('error?.response?.data?.error')
	})
})
