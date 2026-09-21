// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Claim and release: the one-click gesture that takes a case out of the queue.
 *
 * Every assertion here guards a way this feature could ship DARK, and each of
 * the four was reached by reading the library rather than by guessing:
 *
 *  - a `handler` header action resolves its name against the manifest's own
 *    `actions` map, which is JSON, so a function can never answer it: the
 *    button would warn once to the console and do nothing. Hence `api-call`,
 *    asserted by type;
 *  - an `api-call` needs a route to exist behind its url, or the click is a
 *    404 toast;
 *  - an index row action knows only `navigate`, `open-page` and a handler
 *    NAME out of the registry, so the row action is asserted to resolve
 *    to a function that is actually exported;
 *  - an icon that is not registered in `src/icons.js` renders no glyph at
 *    all, so both new icons are asserted to be registered.
 *
 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { claimCase } from '../../src/utils/caseClaim.js'

const mockShowSuccess = vi.fn()
const mockShowError = vi.fn()

// `@nextcloud/axios`, `@nextcloud/router` and `@nextcloud/l10n` are aliased to
// the suite's own stubs in vitest.config.js; only the toasts need a mock here.
vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (...a) => mockShowSuccess(...a),
	showError: (...a) => mockShowError(...a),
}))

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')

/**
 * One page out of the manifest, by id.
 *
 * @param {string} id The page id.
 * @return {object} The page.
 */
function page(id) {
	const found = manifest.pages.find((p) => p.id === id)
	expect(found, `page ${id} is missing from the manifest`).toBeTruthy()
	return found
}

/**
 * One action out of a list, by id.
 *
 * @param {Array} list The actions.
 * @param {string} id The action id.
 * @return {object} The action.
 */
function action(list, id) {
	const found = (list || []).find((a) => a.id === id)
	expect(found, `action ${id} is missing`).toBeTruthy()
	return found
}

describe('the case page offers Claim and Release', () => {
	const headerActions = page('CaseDetail').config.headerActions

	it('claims through an api-call, not a handler the manifest cannot hold', () => {
		const claim = action(headerActions, 'case-claim')
		expect(claim.type).toBe('api-call')
		expect(claim.method).toBe('POST')
		expect(claim.url).toBe('/apps/dossiq/api/case/@objectId/claim')
		expect(claim.label).toBe('Claim')
	})

	it('releases through the same shape', () => {
		const release = action(headerActions, 'case-release')
		expect(release.type).toBe('api-call')
		expect(release.method).toBe('POST')
		expect(release.url).toBe('/apps/dossiq/api/case/@objectId/release')
		expect(release.label).toBe('Release')
	})

	it('offers both on an open case only, and never the two together', () => {
		const open = { field: 'isFinalStatus', op: 'neq', value: true }

		// Claim where CaseAssignmentService::claim() would accept it: nobody
		// holds the case. Release where release() would: the reader holds it.
		// The two assignee clauses cannot both hold, which is the whole point.
		expect(action(headerActions, 'case-claim').visibleWhen).toEqual({
			all: [open, { field: 'assignee', op: 'empty' }],
		})
		expect(action(headerActions, 'case-release').visibleWhen).toEqual({
			all: [open, { field: 'assignee', op: 'eq', value: '@me' }],
		})
	})

	it('leaves the refusal sentence to the server', () => {
		// The library shows `errorMessage` when the action carries one and the
		// response's own `error` when it does not. The server's sentence is the
		// one that says WHY the claim was refused, so neither action may set it.
		for (const id of ['case-claim', 'case-release']) {
			expect(action(headerActions, id).errorMessage).toBeUndefined()
		}
	})

	it('has a route behind each url', () => {
		expect(routes).toContain("'url' => '/api/case/{caseId}/claim'")
		expect(routes).toContain("'url' => '/api/case/{caseId}/release'")
		expect(routes).toContain("'url' => '/api/case/{caseId}/assignment'")
		expect(routes).toContain("'name' => 'caseAssignment#claim'")
		expect(routes).toContain("'name' => 'caseAssignment#release'")
		expect(routes).toContain("'name' => 'caseAssignment#state'")
	})

	it('names icons that are registered', () => {
		for (const id of ['case-claim', 'case-release']) {
			const icon = action(headerActions, id).icon
			expect(
				iconsSource,
				`${icon} is not registered in src/icons.js`,
			).toContain(`\n\t${icon},\n`)
		}
	})
})

describe('the queue and the case list offer Claim on a row', () => {
	it('declares the row action on both indexes', () => {
		for (const id of ['Queue', 'Cases']) {
			const claim = action(page(id).config.actions, 'claim')
			expect(claim.type).toBe('handler')
			expect(claim.handler).toBe('claimCase')
			expect(claim.label).toBe('Claim')
			expect(iconsSource).toContain(`\n\t${claim.icon},\n`)
		}
	})

	it('resolves that handler to a function in the custom-component registry', () => {
		// Asserted against the SOURCE rather than by importing the module:
		// registry.js imports every surviving page and tab, so a unit test that
		// imported it would mount the component tree to reach one function. The
		// imported `claimCase` below is the same function this file registers,
		// and the test above pins the manifest to that name.
		expect(registrySource).toContain(
			"import { claimCase } from './utils/caseClaim.js'",
		)
		expect(registrySource).toMatch(
			/\n\tclaimCase: \{\n\t\tkind: 'handler',\n\t\thandler: claimCase,\n/,
		)
		expect(typeof claimCase).toBe('function')
	})
})

describe('the row handler posts the claim and says what came back', () => {
	beforeEach(() => {
		axios.post.mockReset()
		mockShowSuccess.mockReset()
		mockShowError.mockReset()
	})

	it('posts the case from the row it was used on', async () => {
		axios.post.mockResolvedValue({ data: { mine: true } })

		await claimCase({ actionId: 'claim', item: { id: 'case-7' } })

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post.mock.calls[0][0]).toBe(
			'/index.php/apps/dossiq/api/case/case-7/claim',
		)
		expect(mockShowSuccess).toHaveBeenCalledWith(
			'You are now handling this case.',
		)
	})

	it('reads the id off the metadata when the row carries no plain one', async () => {
		axios.post.mockResolvedValue({ data: {} })

		await claimCase({ actionId: 'claim', item: { '@self': { id: 'case-9' } } })

		expect(axios.post.mock.calls[0][0]).toBe(
			'/index.php/apps/dossiq/api/case/case-9/claim',
		)
	})

	it('shows the refusal the server wrote, unchanged', async () => {
		axios.post.mockRejectedValue({
			response: {
				status: 409,
				data: {
					error: 'Someone else is already handling this case.',
					code: 'already_assigned',
				},
			},
		})

		await claimCase({ actionId: 'claim', item: { id: 'case-7' } })

		expect(mockShowError).toHaveBeenCalledWith(
			'Someone else is already handling this case.',
		)
		expect(mockShowSuccess).not.toHaveBeenCalled()
	})

	it('falls back to a sentence of its own when the server wrote none', async () => {
		axios.post.mockRejectedValue(new Error('network down'))

		await claimCase({ actionId: 'claim', item: { id: 'case-7' } })

		expect(mockShowError).toHaveBeenCalledWith('This did not work. Try again.')
	})

	it('posts nothing for a row with no id', async () => {
		await claimCase({ actionId: 'claim', item: {} })

		expect(axios.post).not.toHaveBeenCalled()
		expect(mockShowError).not.toHaveBeenCalled()
	})

	it('tells the app the caseload changed, so no second claim is invited', async () => {
		axios.post.mockResolvedValue({ data: { mine: true } })
		const seen = []
		const listener = (e) => seen.push(e.type)
		window.addEventListener('dossiq:cases-changed', listener)

		await claimCase({ actionId: 'claim', item: { id: 'case-7' } })
		window.removeEventListener('dossiq:cases-changed', listener)

		expect(seen).toEqual(['dossiq:cases-changed'])
	})
})
