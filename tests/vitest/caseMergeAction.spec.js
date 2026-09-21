// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Merge into: the gesture that makes two cases one.
 *
 * Every assertion here guards a way this action could ship dark, and each one
 * has a named failure mode rather than a shape:
 *
 *  - an `open-modal` action whose `target` is not a registered modal opens
 *    nothing and warns once to the console, so the target is asserted against
 *    `src/registry.js`;
 *  - a registry-mounted modal whose `open` prop defaults false renders no
 *    markup at all and never warns, so the action is asserted to pass it;
 *  - an icon that is not registered in `src/icons.js` renders no glyph, and
 *    an action with no glyph reads as a gap in the row of header actions;
 *  - the dialog posts to `/api/case/{id}/merge`, so that url is asserted to
 *    have a route behind it: without one the confirm is a 404 toast;
 *  - the public status page follows `mergedInto` through a dossiq endpoint
 *    that must equally have a route, or a merged case's link stays on the
 *    case nobody works on.
 *
 * @spec openspec/changes/case-merge/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')
const dialog = fs.readFileSync(
	path.join(ROOT, 'src', 'dialogs', 'CaseMergeDialog.vue'),
	'utf8',
)
const publicPage = fs.readFileSync(
	path.join(ROOT, 'src', 'views', 'public', 'PublicStatusPage.vue'),
	'utf8',
)

/**
 * The Merge into header action on the case page.
 *
 * @return {object} The action.
 */
function mergeAction() {
	const page = manifest.pages.find((p) => p.id === 'CaseDetail')
	expect(page, 'CaseDetail is missing from the manifest').toBeTruthy()
	const found = (page.config.headerActions || []).find(
		(a) => a.id === 'case-merge',
	)
	expect(found, 'the case-merge header action is missing').toBeTruthy()
	return found
}

describe('Merge into, on the case page', () => {
	it('opens a modal the registry actually holds', () => {
		const action = mergeAction()

		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('CaseMergeDialog')
		expect(registrySource).toContain('CaseMergeDialog: {')
		expect(registrySource).toContain(
			"import CaseMergeDialog from './dialogs/CaseMergeDialog.vue'",
		)
	})

	it('passes the open prop, without which the modal renders nothing', () => {
		expect(mergeAction().props.open).toBe(true)
		expect(mergeAction().props.caseId).toBe('@objectId')
	})

	it('uses an icon that is registered', () => {
		expect(iconsSource).toContain(
			`import ${mergeAction().icon} from 'vue-material-design-icons/${mergeAction().icon}.vue'`,
		)
		expect(iconsSource).toContain(`\t${mergeAction().icon},`)
	})

	it('is hidden on a closed case and on nothing else', () => {
		expect(mergeAction().visibleWhen).toEqual({
			field: 'isFinalStatus',
			op: 'neq',
			value: true,
		})
	})

	it('confirms against a url a route answers', () => {
		expect(dialog).toContain(
			'/api/case/${encodeURIComponent(this.targetCaseId)}/merge',
		)
		expect(routes).toContain("'url' => '/api/case/{caseId}/merge'")
		expect(routes).toContain("'name' => 'caseMerge#merge'")
	})
})

describe('The public status page after a merge', () => {
	it('follows mergedInto through a url a route answers', () => {
		expect(publicPage).toContain('obj.mergedInto')
		expect(publicPage).toContain(
			'/apps/dossiq/api/public/case-tokens/${encodeURIComponent(this.token)}/survivor',
		)
		expect(routes).toContain(
			"'url' => '/api/public/case-tokens/{token}/survivor'",
		)
		expect(routes).toContain("'name' => 'publicCaseSurvivor#survivor'")
	})

	it('names the request the applicant filed, so the page is not a silent swap', () => {
		expect(publicPage).toContain('public-status-merged-from')
	})
})
