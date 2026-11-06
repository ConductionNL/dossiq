// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Lodge a remedy from the case page.
 *
 * The dialog is generic and the manifest action configures it, so every test
 * mounts it with the props the REAL action carries.
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-from-the-case-page-req-rml-003
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RemedyLodgeDialog from '../../src/dialogs/RemedyLodgeDialog.vue'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const routesSource = fs.readFileSync(
	path.join(ROOT, 'appinfo', 'routes.php'),
	'utf8',
)
const controllerSource = fs.readFileSync(
	path.join(ROOT, 'lib', 'Controller', 'RemedyController.php'),
	'utf8',
)
const CASE_ID = '11111111-1111-4111-8111-111111111111'
const NEW_CASE = '22222222-2222-4222-8222-222222222222'

const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
const action = caseDetail.config.headerActions.find(
	(entry) => entry.id === 'remedy-lodge',
)

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

/**
 * Mount the dialog as the manifest action configures it.
 *
 * @return {Promise<{wrapper: object, axios: object, push: Function}>} The dialog, the axios double and the router's push.
 */
async function mountDialog() {
	const axios = (await import('@nextcloud/axios')).default
	const push = vi.fn()
	const wrapper = mount(RemedyLodgeDialog, {
		props: { ...action.props, caseId: CASE_ID },
		global: {
			mocks: { $router: { push }, $route: { params: { id: CASE_ID } } },
			stubs: {
				NcDialog: {
					props: ['name'],
					template:
						'<div><h2 data-testid="title">{{ name }}</h2><slot /><slot name="actions" /></div>',
				},
				NcButton: {
					props: ['disabled'],
					emits: ['click'],
					template:
						'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
				},
				NcSelect: {
					props: ['modelValue', 'options', 'inputLabel'],
					template: '<div :data-label="inputLabel" />',
				},
				NcDateTimePicker: true,
				NcTextArea: true,
			},
		},
	})
	await flushPromises()

	return { wrapper, axios, push }
}

/**
 * Fill the form the way a handler would.
 *
 * @param {object} wrapper The mounted dialog.
 * @return {Promise<void>}
 */
async function fill(wrapper) {
	wrapper.vm.receivedDate = new Date(2026, 11, 1, 0, 30)
	wrapper.vm.channel = wrapper.vm.channelOptions.find(
		(option) => option.id === 'brief',
	)
	wrapper.vm.grounds = '  Het besluit is onvolledig.  '
	await flushPromises()
}

describe('RemedyLodgeDialog', () => {
	beforeEach(async () => {
		const axios = (await import('@nextcloud/axios')).default
		axios.post.mockReset()
	})

	it('carries the title and the channels the case type configured', async () => {
		const { wrapper } = await mountDialog()

		expect(wrapper.find('[data-testid="title"]').text()).toBe(
			'Register an objection',
		)
		expect(wrapper.vm.channelOptions.map((option) => option.id)).toEqual([
			'brief',
			'email',
			'form',
			'balie',
		])
	})

	it('offers nothing to save without a channel and grounds', async () => {
		const { wrapper } = await mountDialog()

		expect(
			wrapper.find('[data-testid="remedy-lodge-save"]').attributes('disabled'),
		).toBeDefined()
		wrapper.vm.channel = wrapper.vm.channelOptions[0]
		wrapper.vm.grounds = '   '
		await flushPromises()
		expect(
			wrapper.find('[data-testid="remedy-lodge-save"]').attributes('disabled'),
		).toBeDefined()
	})

	it('posts the lodging once, with the local calendar day', async () => {
		const { wrapper, axios } = await mountDialog()
		axios.post.mockResolvedValue({
			data: {
				caseId: NEW_CASE,
				objectionId: 'obj-1',
				deadline: '2027-01-12',
				isTimely: true,
			},
		})

		await fill(wrapper)
		await wrapper.find('[data-testid="remedy-lodge-save"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toContain(`/apps/dossiq/api/cases/${CASE_ID}/remedy`)
		// 00:30 local on 1 December is still 1 December, whatever UTC says.
		expect(body).toEqual({
			receivedDate: '2026-12-01',
			receivedChannel: 'brief',
			grounds: 'Het besluit is onvolledig.',
			requestedRelief: '',
		})
		expect(wrapper.find('[data-testid="remedy-lodge-done"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="remedy-lodge-late"]').exists()).toBe(
			false,
		)
	})

	it('testItNavigatesToTheNewBezwaarCase', async () => {
		const { wrapper, axios, push } = await mountDialog()
		axios.post.mockResolvedValue({
			data: {
				caseId: NEW_CASE,
				objectionId: 'obj-1',
				deadline: '2027-01-12',
				isTimely: true,
			},
		})

		await fill(wrapper)
		await wrapper.find('[data-testid="remedy-lodge-save"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-testid="remedy-lodge-open"]').trigger('click')

		expect(push).toHaveBeenCalledWith({
			name: 'CaseDetail',
			params: { id: NEW_CASE },
		})
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('says a late lodging is registered and marked, before moving on', async () => {
		const { wrapper, axios, push } = await mountDialog()
		axios.post.mockResolvedValue({
			data: {
				caseId: NEW_CASE,
				objectionId: 'obj-1',
				deadline: '2027-01-12',
				isTimely: false,
			},
		})

		await fill(wrapper)
		await wrapper.find('[data-testid="remedy-lodge-save"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="remedy-lodge-late"]').exists()).toBe(true)
		expect(push).not.toHaveBeenCalled()
	})

	it('shows the sentence of a refusal and stays open', async () => {
		const { wrapper, axios, push } = await mountDialog()
		axios.post.mockRejectedValue({
			response: {
				status: 409,
				data: {
					error: 'remedy-no-decision',
					message:
						'This case has no decision yet, so nothing can be lodged against it.',
				},
			},
		})

		await fill(wrapper)
		await wrapper.find('[data-testid="remedy-lodge-save"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="remedy-lodge-error"]').text()).toBe(
			'This case has no decision yet, so nothing can be lodged against it.',
		)
		expect(wrapper.find('[data-testid="remedy-lodge-done"]').exists()).toBe(
			false,
		)
		expect(push).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})

describe('the remedy-lodge header action', () => {
	it('opens the registered dialog on a route that exists', () => {
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('RemedyLodgeDialog')
		expect(registrySource).toMatch(/RemedyLodgeDialog: \{\s*kind: 'modal'/)
		expect(action.props.lodgeUrl).toBe('/apps/dossiq/api/cases/{caseId}/remedy')
		expect(routesSource).toContain(
			"'url' => '/api/cases/{id}/remedy', 'verb' => 'POST'",
		)
		expect(
			manifest.pages.some((page) => page.id === action.props.openRoute.name),
		).toBe(true)
	})

	it('offers exactly the channels the route accepts and sends the fields it reads', () => {
		// RemedyLodging refuses any other channel with 422, so a channel the
		// manifest invents is a button that can only fail.
		const lodging = fs.readFileSync(
			path.join(ROOT, 'lib', 'Service', 'Remedy', 'RemedyLodging.php'),
			'utf8',
		)
		const accepted = lodging
			.match(/const CHANNELS = \[([^\]]+)\]/)[1]
			.split(',')
			.map((entry) => entry.trim().replace(/'/g, ''))
		expect(accepted.length).toBeGreaterThan(0)
		expect(action.props.channels.map((channel) => channel.value).sort()).toEqual(
			[...accepted].sort(),
		)
		for (const field of [
			'receivedDate',
			'receivedChannel',
			'grounds',
			'requestedRelief',
		]) {
			expect(controllerSource, field).toContain(`'${field}'`)
		}
	})

	it('is scoped to the case type that configures it', () => {
		expect(action.visibleWhen.all).toContainEqual({
			field: 'caseTypeIdentifier',
			op: 'eq',
			value: 'woo-verzoek',
		})
		expect(action.visibleWhen.all).toContainEqual({
			field: '@self.archived',
			op: 'empty',
		})
	})
})

describe('where a lodged objection is listed', () => {
	// REQ-WCS-006. No Bezwaren page: the page of that name was deleted on
	// 2026-09-02 because it was an index over `case` narrowed by case type,
	// which is what the Cases page already is through its case type folders
	// (src/menu-layout.json removalsCoverageNote). The Bezwaar case type is
	// seeded now, so it is one of those folders and its cases are real rows.
	const seeded = JSON.parse(
		fs.readFileSync(
			path.join(
				ROOT,
				'lib',
				'Settings',
				'register.d',
				'84-woo-bezwaar-case-type.json',
			),
			'utf8',
		),
	)
	const bezwaar = seeded.components.objects.find(
		(object) => object['@self']?.schema === 'caseType',
	)

	it('lists cases per case type on the Cases page, and Bezwaar is a seeded case type', () => {
		const lists = manifest.pages.filter(
			(page) => page.id === 'Cases' && page.config?.folderSidebar,
		)
		expect(lists.length).toBeGreaterThan(0)
		for (const page of lists) {
			expect(page.config.schema).toBe('case')
			expect(page.config.folderSidebar).toMatchObject({
				source: 'register',
				schema: 'caseType',
				filterField: 'caseType',
			})
		}
		expect(bezwaar.title).toBe('Bezwaar')
		expect(bezwaar.isDraft).toBe(false)
		expect(manifest.pages.some((page) => page.id === 'Bezwaren')).toBe(false)
	})
})
