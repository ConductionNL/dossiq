/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * BezwaarAdviceRequestDetail: an advice request changes status only through
 * dossiq's own transition endpoint, never through a generic OpenRegister
 * lifecycle write. Starting deliberation, issuing the advice and declaring the
 * objection inadmissible are header actions that POST the target status there.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const en = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'en.json'), 'utf8')).translations
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const page = manifest.pages.find((entry) => entry.id === 'BezwaarAdviceRequestDetail')
const actions = page.config.headerActions || []
const byId = (id) => actions.find((action) => action.id === id)
const TRANSITION_URL = '/apps/dossiq/api/bezwaar/advice-requests/@objectId/transition'
const COMMITTEE = { readerGroups: ['bezwaarcommissie', 'admin'] }

describe('BezwaarAdviceRequestDetail status actions', () => {
	it('declares no lifecycleActions, so nothing writes status straight to OpenRegister', () => {
		expect(page.config.lifecycleActions).toBeUndefined()
		const writesStatusDirectly = actions.filter((action) => action.type !== 'api-call'
			|| !String(action.url || '').startsWith('/apps/dossiq/'))
		expect(writesStatusDirectly).toEqual([])
	})

	it('offers exactly the three transitions, each as an api-call to the dossiq transition route', () => {
		expect(actions.map((action) => action.id)).toEqual(['start-deliberation', 'issue-advice', 'declare-inadmissible'])
		for (const action of actions) {
			expect(action.type).toBe('api-call')
			expect(action.url).toBe(TRANSITION_URL)
			expect(action.method ?? 'POST').toBe('POST')
		}
	})

	it('starts deliberation on an assigned request, without asking first', () => {
		const action = byId('start-deliberation')
		expect(action.payload).toEqual({ status: 'in-deliberation' })
		expect(action.confirm).toBeUndefined()
		expect(action.visibleWhen).toEqual({ ...COMMITTEE, field: 'status', op: 'eq', value: 'assigned' })
	})

	it('issues the advice from deliberation, after a confirmation', () => {
		const action = byId('issue-advice')
		expect(action.payload).toEqual({ status: 'advice-issued' })
		expect(action.confirm).toBe(true)
		expect(action.visibleWhen).toEqual({ ...COMMITTEE, field: 'status', op: 'eq', value: 'in-deliberation' })
	})

	it('declares the objection inadmissible from deliberation, after a confirmation', () => {
		const action = byId('declare-inadmissible')
		expect(action.payload).toEqual({ status: 'inadmissible' })
		expect(action.confirm).toBe(true)
		expect(action.visibleWhen).toEqual({ ...COMMITTEE, field: 'status', op: 'eq', value: 'in-deliberation' })
	})

	it('shows each action only to the bezwaarcommissie and admin groups, as the endpoint allows', () => {
		for (const action of actions) {
			expect(action.visibleWhen.readerGroups).toEqual(COMMITTEE.readerGroups)
		}
	})

	it('refreshes the page after a transition, so the new status shows', () => {
		for (const action of actions) {
			expect(action.refresh).not.toBe(false)
		}
	})

	it('has English and Dutch words for every label and message', () => {
		expect(nl[byId('start-deliberation').label]).toBe('Beraadslaging starten')
		expect(nl[byId('issue-advice').label]).toBe('Advies uitbrengen')
		expect(nl[byId('declare-inadmissible').label]).toBe('Niet-ontvankelijk verklaren')
		const texts = actions.flatMap((action) => [action.label, action.confirmMessage, action.successMessage, action.errorMessage])
			.filter((text) => typeof text === 'string')
		for (const text of texts) {
			expect(en[text], text).toBe(text)
			expect(nl[text], text).toBeTruthy()
			expect(nl[text], text).not.toContain('—')
		}
	})
})
