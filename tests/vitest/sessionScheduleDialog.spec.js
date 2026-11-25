// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Plan a session from the case page: the dialog reads the sessions the case
 * type declares, offers one name field per invitee role, posts the plan and
 * keeps a refusal as the server words it. Also checks that the case page
 * offers the action only on a case type that declares sessions, and that the
 * registry knows the dialog.
 *
 * @spec openspec/changes/complaints-are-a-case-type/tasks.md#2.3
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SessionScheduleDialog from '../../src/dialogs/SessionScheduleDialog.vue'

const mockGet = vi.fn()
const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...a) => mockGet(...a),
		post: (...a) => mockPost(...a),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
const mockSuccess = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: (...a) => mockSuccess(...a) }))

const STUBS = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: { props: ['modelValue', 'options'], template: '<select />' },
	NcDateTimePicker: { props: ['modelValue'], template: '<input />' },
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	CalendarClock: true,
}

const HEARING = {
	key: 'hoorgesprek',
	label: 'Hoorgesprek',
	inviteeRoles: ['Klager', 'Betrokken medewerker', 'Klachtencoördinator'],
	minInvitees: 1,
	inspectionFloorDays: 7,
	waivable: true,
}

/**
 * Mount the dialog for case-1 and wait for its read.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog() {
	const wrapper = mount(SessionScheduleDialog, { props: { caseId: 'case-1' }, global: { stubs: STUBS } })
	await flushPromises()
	return wrapper
}

describe('plan a session', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		mockGet.mockResolvedValue({ data: { definitions: [HEARING], sessions: [] } })
		mockPost.mockResolvedValue({ data: { id: 'session-1' } })
	})

	it('plans the only declared session with the invitees that have a name', async () => {
		const wrapper = await mountDialog()

		expect(mockGet).toHaveBeenCalledWith('/apps/dossiq/api/cases/case-1/sessions')
		expect(wrapper.vm.sessionKey).toBe('hoorgesprek')
		expect(wrapper.find('[data-testid="session-schedule-session"]').exists()).toBe(false)
		expect(wrapper.findAll('[data-testid^="session-schedule-invitee-"]')).toHaveLength(3)
		expect(wrapper.find('[data-testid="session-schedule-floor"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="session-schedule-submit"]').attributes('disabled')).toBeDefined()

		wrapper.vm.when = new Date('2026-12-10T09:00:00Z')
		await wrapper.find('[data-testid="session-schedule-location"]').setValue(' Stadhuis, kamer 2 ')
		await wrapper.find('[data-testid="session-schedule-invitee-Klager"]').setValue(' Sanne de Vries ')
		await wrapper.find('[data-testid="session-schedule-submit"]').trigger('click')
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/cases/case-1/sessions', {
			sessionKey: 'hoorgesprek',
			scheduledDate: '2026-12-10T09:00:00.000Z',
			invitees: [{ role: 'Klager', name: 'Sanne de Vries' }],
			payload: { location: 'Stadhuis, kamer 2' },
		})
		expect(mockSuccess).toHaveBeenCalledWith('Hoorgesprek planned.')
		expect(wrapper.emitted('done')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('does not plan without a moment or fewer invitees than the session needs', async () => {
		const wrapper = await mountDialog()
		await wrapper.find('[data-testid="session-schedule-invitee-Klager"]').setValue('Sanne de Vries')
		expect(wrapper.vm.canPlan).toBe(false)

		wrapper.vm.when = new Date('2026-12-10T09:00:00Z')
		await wrapper.find('[data-testid="session-schedule-invitee-Klager"]').setValue('  ')
		expect(wrapper.vm.canPlan).toBe(false)
	})

	it('asks which session when the case type declares more than one', async () => {
		mockGet.mockResolvedValue({ data: { definitions: [HEARING, { key: 'zitting', label: 'Zitting', inviteeRoles: [], minInvitees: 0 }], sessions: [] } })
		const wrapper = await mountDialog()

		expect(wrapper.vm.sessionKey).toBe(null)
		expect(wrapper.find('[data-testid="session-schedule-session"]').exists()).toBe(true)
		expect(wrapper.vm.sessionOptions).toEqual([{ id: 'hoorgesprek', label: 'Hoorgesprek' }, { id: 'zitting', label: 'Zitting' }])
	})

	it('says so when the case type declares no session', async () => {
		mockGet.mockResolvedValue({ data: { definitions: [], sessions: [] } })
		const wrapper = await mountDialog()

		expect(wrapper.find('[data-testid="session-schedule-empty"]').exists()).toBe(true)
		expect(wrapper.vm.canPlan).toBe(false)
	})

	it('keeps a refused plan in the dialog as the server words it', async () => {
		mockPost.mockRejectedValue({ response: { data: { message: 'Hoorgesprek needs 7 days for inspection.' } } })
		const wrapper = await mountDialog()
		wrapper.vm.when = new Date('2026-11-27T09:00:00Z')
		await wrapper.find('[data-testid="session-schedule-invitee-Klager"]').setValue('Sanne de Vries')
		await wrapper.find('[data-testid="session-schedule-submit"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="session-schedule-error"]').text()).toBe('Hoorgesprek needs 7 days for inspection.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('shows a failed read as a refusal', async () => {
		mockGet.mockRejectedValue({ response: { data: { message: 'You may not read this case.' } } })
		const wrapper = await mountDialog()

		expect(wrapper.find('[data-testid="session-schedule-error"]').text()).toBe('You may not read this case.')
		expect(wrapper.find('[data-testid="session-schedule-empty"]').exists()).toBe(false)
	})
})

describe('the session header action', () => {
	const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
	const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
	const registry = readFileSync(resolve(__dirname, '../../src/registry.js'), 'utf8')

	it('opens SessionScheduleDialog for the case on a case type that declares sessions and is not archived', () => {
		const action = caseDetail.config.headerActions.find((row) => row.id === 'session-schedule')

		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('SessionScheduleDialog')
		expect(action.props).toEqual({ caseId: '@objectId' })
		expect(action.visibleWhen.all).toEqual([
			{ field: '@context.caseType.sessions', op: 'notEmpty' },
			{ field: '@self.archived', op: 'empty' },
		])
		expect(registry).toContain('\tSessionScheduleDialog: {')
	})
})
