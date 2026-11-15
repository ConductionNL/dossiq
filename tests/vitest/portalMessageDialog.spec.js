// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The handler answers a resident from the case
 * (communication-portal-conversation-on-the-case, task 5.2).
 *
 * The payload is validated against the REAL `portaalBericht` fragment, not
 * against what the dialog thinks the schema says: a message the schema refuses
 * is a dialog that reports a send and a resident who never gets the answer.
 * The one rule that matters most is `recipientRef`: the inbox is scoped on it,
 * so a handler's message must carry the case's `portalSubject` there, never a
 * user id.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const fragment = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'lib', 'Settings', 'register.d', '50-zaakportaal.json'),
		'utf8',
	),
)
const messageSchema = fragment.components.schemas.portaalBericht

const mockGet = vi.fn()
const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
const mockBusEmit = vi.fn()
vi.mock('@nextcloud/event-bus', () => ({ emit: (...a) => mockBusEmit(...a), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'handler-1', displayName: 'Petra de Vries' }),
}))

/**
 * A stand-in for one @nextcloud/vue control.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function control(name) {
	return defineComponent({
		name,
		props: [
			'modelValue',
			'label',
			'error',
			'disabled',
			'variant',
			'type',
			'size',
			'name',
		],
		emits: ['update:modelValue', 'close', 'click'],
		render() {
			return h(
				'div',
				{ class: name, onClick: () => this.$emit('click') },
				this.$slots.default?.() ?? [],
			)
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcDialog: control('NcDialog'),
	NcNoteCard: control('NcNoteCard'),
	NcTextField: control('NcTextField'),
	NcTextArea: control('NcTextArea'),
}))

const { default: PortalMessageDialog } =
	await import('../../src/dialogs/PortalMessageDialog.vue')

const CASE = {
	id: 'case-1',
	identifier: 'Z/2026/09128',
	title: 'Kapvergunning Dorpsstraat 4',
	portalSubject: 'subject-resident',
}

/**
 * Mount the dialog open on one case.
 *
 * @param {object} props Extra props.
 * @return {object} The wrapper.
 */
function mountDialog(props = {}) {
	return mount(PortalMessageDialog, {
		props: { caseId: 'case-1', open: true, ...props },
		global: { mocks: { t: (app, s) => s } },
	})
}

/**
 * Whether a payload fits the schema: every key a property, every required
 * key filled, every enum value allowed.
 *
 * @param {object} payload The message.
 * @return {Array<string>} The problems, empty when it fits.
 */
function problems(payload) {
	const found = []
	for (const [key, value] of Object.entries(payload)) {
		const property = messageSchema.properties[key]
		if (!property) {
			found.push(`${key} is not a property`)
			continue
		}
		if (property.enum && !property.enum.includes(value)) {
			found.push(`${key} = ${value} is not in the enum`)
		}
	}
	for (const key of messageSchema.required) {
		if (!payload[key]) {
			found.push(`${key} is required`)
		}
	}
	return found
}

describe('PortalMessageDialog', () => {
	beforeEach(() => {
		mockGet.mockReset()
		mockPost.mockReset()
		mockGet.mockResolvedValue({ data: CASE })
		mockPost.mockResolvedValue({ data: { id: 'msg-9' } })
	})

	it('writes a handler_to_citizen message addressed to the case portal subject', async () => {
		const wrapper = mountDialog()
		await flushPromises()

		wrapper.vm.form.subject = 'Re: Termijn'
		wrapper.vm.form.content = 'Within two weeks.'
		await wrapper.vm.send()
		await flushPromises()

		expect(mockPost).toHaveBeenCalledTimes(1)
		const [url, payload] = mockPost.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/objects/dossiq/portaalBericht')
		expect(payload.direction).toBe('handler_to_citizen')
		expect(payload.recipientRef).toBe(CASE.portalSubject)
		expect(payload.recipientRef).not.toBe('handler-1')
		expect(payload.caseId).toBe('case-1')
		expect(payload.caseReference).toBe(CASE.identifier)
		expect(payload.senderType).toBe('medewerker')
		expect(payload.senderName).toBe('Petra de Vries')
		expect(payload.content).toBe('Within two weeks.')
		expect(problems(payload)).toEqual([])

		expect(wrapper.emitted('sent')[0][0]).toEqual({ id: 'msg-9' })
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('tells the page a reply on this case was sent, so a saved draft is cleared (DqZaakContact)', async () => {
		mockBusEmit.mockReset()
		const wrapper = mountDialog()
		await flushPromises()

		wrapper.vm.form.subject = 'Re: Termijn'
		wrapper.vm.form.content = 'Within two weeks.'
		await wrapper.vm.send()
		await flushPromises()

		expect(mockBusEmit).toHaveBeenCalledWith('cn:conversation:reply-sent', { objectId: 'case-1' })
	})

	it('reads the case from the route when the action passes the unresolved token', async () => {
		const wrapper = mount(PortalMessageDialog, {
			props: { caseId: '@objectId', open: true },
			global: {
				mocks: { t: (app, s) => s, $route: { params: { id: 'case-1' } } },
			},
		})
		await flushPromises()

		expect(mockGet.mock.calls[0][0]).toBe(
			'/apps/openregister/api/objects/dossiq/case/case-1',
		)
		expect(wrapper.vm.resolvedCaseId).toBe('case-1')
	})

	it('prefills the subject when it answers a message', async () => {
		const wrapper = mountDialog({ subject: 'Termijn' })
		await flushPromises()

		expect(wrapper.vm.form.subject).toBe('Re: Termijn')
	})

	it('sends nothing to a case without a portal subject, and says why', async () => {
		mockGet.mockResolvedValue({ data: { ...CASE, portalSubject: '' } })
		const wrapper = mountDialog()
		await flushPromises()

		wrapper.vm.form.content = 'Hello'
		await wrapper.vm.send()

		expect(mockPost).not.toHaveBeenCalled()
		expect(wrapper.vm.error).not.toBe('')
		expect(wrapper.emitted('close')).toBeFalsy()
	})

	it('sends nothing without a message, and keeps the dialog open on a failed save', async () => {
		const wrapper = mountDialog()
		await flushPromises()

		await wrapper.vm.send()
		expect(mockPost).not.toHaveBeenCalled()

		mockPost.mockRejectedValue(new Error('400'))
		wrapper.vm.form.content = 'Within two weeks.'
		await wrapper.vm.send()
		await flushPromises()

		expect(wrapper.vm.error).not.toBe('')
		expect(wrapper.emitted('sent')).toBeFalsy()
	})
})

describe('the Message the applicant header action', () => {
	const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
	const action = caseDetail.config.headerActions.find(
		(a) => a.id === 'message-applicant',
	)

	it('opens the dialog from the case page, only for a case with a portal subject', () => {
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('PortalMessageDialog')
		expect(action.props).toEqual({ caseId: '@objectId', open: true })
		expect(action.visibleWhen.all).toContainEqual({
			field: 'portalSubject',
			op: 'notEmpty',
		})
	})

	it('is a registered modal', () => {
		expect(registrySource).toMatch(/PortalMessageDialog:\s*{\s*kind: 'modal'/)
	})
})

describe('Reply on a resident message in the timeline', () => {
	it('offers Reply on a resident message only, and closes its follow-up once the answer is sent', () => {
		// The timeline is the library `timeline` widget in manage mode; it
		// opens the registry modal an entry action names, resolves its props
		// and closes the entry's open follow-up on the action's done event
		// (pinned in nextcloud-vue tests/components/CnTimelineManage.spec.js).
		const pane = manifest.pages
			.find((page) => page.id === 'CaseDetail')
			.config.widgets.find((widget) => widget.id === 'case-timeline-panel')
		const reply = pane.content.entryActions.find((action) => action.id === 'reply')

		expect(reply.kinds).toEqual(['portaalbericht-inkomend'])
		expect(reply.modal).toBe('PortalMessageDialog')
		expect(reply.props).toEqual({
			open: true,
			caseId: '@objectId',
			subject: '@row.fields.subject',
		})
		expect(reply.doneEvent).toBe('sent')
		expect(reply.closesFollowUp).toBe(true)
	})

	it('also logs the answer as a contact moment on the case when asked to', async () => {
		mockPost.mockImplementation(async (url) => (url.endsWith('/contact-moments') ? { data: { contactmoment: { id: 'cm-1' } } } : { data: { id: 'msg-9' } }))
		const wrapper = mountDialog({ logContactMoment: true })
		await flushPromises()

		wrapper.vm.form.content = 'We sent the documents.'
		await wrapper.vm.send()
		await flushPromises()

		expect(mockPost).toHaveBeenCalledTimes(2)
		const [url, moment] = mockPost.mock.calls[1]
		expect(url).toContain('/pipelinq/contact-moments')
		expect(moment).toMatchObject({ notificationChannel: 'webformulier', direction: 'outbound', summary: 'We sent the documents.', visibleToApplicant: true })
		expect(typeof moment.startTime).toBe('string')
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('logs nothing without the option', async () => {
		const wrapper = mountDialog()
		await flushPromises()
		wrapper.vm.form.content = 'Hello'
		await wrapper.vm.send()
		await flushPromises()
		expect(mockPost).toHaveBeenCalledTimes(1)
	})

	it('says so when the message went but the contact moment did not, and stays open', async () => {
		mockPost.mockImplementation(async (url) => {
			if (url.endsWith('/contact-moments')) {
				throw new Error('500')
			}
			return { data: { id: 'msg-9' } }
		})
		const wrapper = mountDialog({ logContactMoment: true })
		await flushPromises()
		wrapper.vm.form.content = 'Hello'
		await wrapper.vm.send()
		await flushPromises()
		expect(wrapper.emitted('sent')[0][0]).toEqual({ id: 'msg-9' })
		expect(wrapper.emitted('close')).toBeFalsy()
		expect(wrapper.vm.error).toBe('The message was sent, but it could not be logged as a contact moment.')
	})
})
