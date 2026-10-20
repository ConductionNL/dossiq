// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The coordinator's substitution console, now a CnIndexPage in the Substitution
 * tab of the admin settings over dossiq's /api/substitutions (which keeps the
 * coordinator check). Everything the console did must still be there: search by
 * handler, register on behalf, bulk reassignment, the actions done under one,
 * and revoking an active one behind a confirm that shows the server's refusal.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const mockFetchActions = vi.hoisted(() => vi.fn())
vi.mock('../../src/services/substitutionApi.js', () => ({ fetchSubstitutionActions: mockFetchActions }))

const { substitutionIndex } = await import('../../src/views/settings/adminLists.js')
const SubstitutionActionsDialog = (await import('../../src/dialogs/SubstitutionActionsDialog.vue')).default

const ROOT = path.resolve(__dirname, '../..')
const adminRoot = fs.readFileSync(path.join(ROOT, 'src', 'views', 'settings', 'AdminRoot.vue'), 'utf8')

const opened = []
const declaration = substitutionIndex((app, text) => text, (key, props) => opened.push([key, props]))
const actions = Object.fromEntries(declaration.actions.map((action) => [action.id, action]))

describe('the substitution console', () => {
	beforeEach(() => {
		opened.length = 0
		mockFetchActions.mockReset()
	})

	it('lists the endpoint that checks the coordinator role, searched by handler', () => {
		expect(declaration.entitySource).toBe('endpoint')
		expect(declaration.sourceConfig).toEqual({ url: '/apps/dossiq/api/substitutions', rowsPath: 'results', searchParam: 'handler' })
		expect(declaration.inlineSearch).toBe(true)
		expect(declaration.title).toBe('Substitutions & reassignment')
		expect(declaration.columns.map((col) => col.key)).toEqual(['absentee', 'substitute', 'startDate', 'endDate', 'scope', 'status', 'actions'])
	})

	it('says the status in a word, not a colour alone', () => {
		const status = declaration.columns.find((col) => col.key === 'status')
		expect(status.enumLabels).toEqual({ active: 'Active', ended: 'Ended', revoked: 'Revoked' })
	})

	it('registers on behalf of a handler and reassigns in bulk from the header', () => {
		expect(declaration.headerButtons.map((button) => button.action)).toEqual(['bulk-reassign', 'register-substitution'])
		declaration.headerActions.forEach((action) => action.handler())
		expect(opened).toEqual([['BulkReassignModal', undefined], ['SubstitutionFormModal', { allowCoordinator: true }]])
	})

	it('revokes only an active substitution, behind a confirm that posts and shows a refusal', () => {
		expect(actions.revoke.visibleWhen).toEqual({ field: 'status', op: 'eq', value: 'active' })
		expect(actions.revoke).toMatchObject({ type: 'open-modal', target: 'CnActionDialog' })
		expect(actions.revoke.props.config.confirm.request).toEqual({ method: 'POST', url: '/apps/dossiq/api/substitutions/@objectId/revoke' })
	})

	it('hosts every modal its lists open, since the admin page has no app root', () => {
		for (const name of ['BulkReassignModal', 'CnActionDialog', 'SubstitutionActionsDialog', 'SubstitutionFormModal']) {
			expect(adminRoot).toMatch(new RegExp(`const ADMIN_MODALS = \\{[^}]*\\b${name},`))
		}
		expect(adminRoot).toContain('cnOpenModal: (key, props = {}) => this.openAdminModal(key, props)')
		expect(actions['actions-done']).toMatchObject({ type: 'open-modal', target: 'SubstitutionActionsDialog' })
	})

	it('shows the actions done under a substitution, on whose behalf', async () => {
		mockFetchActions.mockResolvedValue([{ timestamp: '2026-10-01', caseTitle: 'Dakkapel', action: 'status changed', actedOnBehalfOf: 'jan' }])
		const wrapper = mount(SubstitutionActionsDialog, {
			props: { objectId: 'v-1' },
			global: { stubs: { NcDialog: { render() { return h('div', this.$slots.default()) } } } },
		})
		await flushPromises()
		expect(mockFetchActions).toHaveBeenCalledWith('v-1')
		expect(wrapper.text()).toContain('Dakkapel')
		expect(wrapper.text()).toContain('on behalf of jan')
	})
})
