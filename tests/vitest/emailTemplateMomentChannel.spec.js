// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The message template editor sets when a message goes out (a case event)
 * and how it reaches the applicant, and saves both with the template.
 *
 * @spec openspec/changes/case-type-messages-moment-channel/specs/case-types/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { MESSAGE_CHANNELS, MESSAGE_MOMENTS } from '../../src/utils/messageDelivery.js'

/**
 * A stub that renders its default slot.
 *
 * @param {string} name The component name.
 * @param {string} tag The element.
 * @return {object} The stub.
 */
function boxStub(name, tag = 'div') {
	return {
		name,
		emits: ['click'],
		render() {
			return h(tag, { class: name, onClick: () => this.$emit('click') }, this.$slots.default ? this.$slots.default() : [])
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: boxStub('NcButton', 'button'),
	NcInputField: boxStub('NcInputField'),
	NcLoadingIcon: boxStub('NcLoadingIcon', 'span'),
	NcNoteCard: boxStub('NcNoteCard'),
	NcTextArea: boxStub('NcTextArea'),
	NcSelect: {
		name: 'NcSelect',
		props: { modelValue: { type: [Object, String], default: null }, options: { type: Array, default: () => [] }, inputLabel: { type: String, default: '' } },
		emits: ['update:modelValue'],
		render() {
			return h('div', { class: 'NcSelect', 'data-label': this.inputLabel })
		},
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

const EmailTemplateAdmin = (await import('../../src/views/casetypes/components/EmailTemplateAdmin.vue')).default

describe('the message template editor', () => {
	let fetchMock

	beforeEach(() => {
		globalThis.t = (app, text) => text
		globalThis.OC = { requestToken: 'tok' }
		fetchMock = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve([]) }))
		globalThis.fetch = fetchMock
	})

	it('offers the moment and the channel', async () => {
		const w = mount(EmailTemplateAdmin, { props: { caseTypeId: 'ct-1' } })
		await flushPromises()
		w.vm.startCreate()
		await flushPromises()
		const selects = w.findAllComponents({ name: 'NcSelect' })
		expect(selects.map((select) => select.props('inputLabel'))).toEqual(['Moment', 'Channel'])
		expect(selects[0].props('options').map((option) => option.value)).toEqual(MESSAGE_MOMENTS.map((option) => option.value))
		expect(selects[1].props('options').map((option) => option.value)).toEqual(MESSAGE_CHANNELS.map((option) => option.value))
	})

	it('saves them with the template, and an unset one as null', async () => {
		const w = mount(EmailTemplateAdmin, { props: { caseTypeId: 'ct-1' } })
		await flushPromises()
		w.vm.selectTemplate({ id: 't-1', name: 'Besluit', subject: 'S', body: 'B', moment: 'decision-made' })
		await w.vm.save()
		const [url, init] = fetchMock.mock.calls.find(([, options]) => options && options.method === 'PUT')
		expect(url).toBe('/apps/dossiq/api/email-templates/t-1')
		expect(JSON.parse(init.body)).toMatchObject({ moment: 'decision-made', channel: null })
	})

	/**
	 * Answer the two reads of the editor by url.
	 *
	 * @param {object} templates The templates response.
	 * @param {object} variables The variables response.
	 * @return {void}
	 */
	function answerReads(templates, variables) {
		fetchMock.mockImplementation((url) => Promise.resolve(url.endsWith('/variables') ? variables : templates))
	}

	const ok = (body) => ({ ok: true, json: () => Promise.resolve(body) })

	it('reads the templates and the variables with the request token', async () => {
		answerReads(ok({ results: [{ id: 't-1' }] }), ok({ case: ['zaaknummer'] }))
		const w = mount(EmailTemplateAdmin, { props: { caseTypeId: 'ct 1' } })
		await flushPromises()

		expect(fetchMock.mock.calls).toEqual([
			['/apps/dossiq/api/casetypes/ct%201/email-templates', { headers: { requesttoken: 'tok' } }],
			['/apps/dossiq/api/casetypes/ct%201/email-templates/variables', { headers: { requesttoken: 'tok' } }],
		])
		expect(w.vm.templates).toEqual([{ id: 't-1' }])
		expect(w.vm.variables).toEqual({ case: ['zaaknummer'] })
		expect(w.vm.loading).toBe(false)
	})

	it.each([
		[[{ id: 'a' }], [{ id: 'a' }]],
		[{ templates: [{ id: 'b' }] }, [{ id: 'b' }]],
		[{}, []],
	])('reads the template list %j', async (body, templates) => {
		answerReads(ok(body), { ok: false })
		const w = mount(EmailTemplateAdmin, { props: { caseTypeId: 'ct-1' } })
		await flushPromises()

		expect(w.vm.templates).toEqual(templates)
		expect(w.vm.variables).toEqual({})
	})

	it('keeps the editor empty when a read fails or is refused', async () => {
		answerReads({ ok: false }, ok({ case: ['x'] }))
		const w = mount(EmailTemplateAdmin, { props: { caseTypeId: 'ct-1' } })
		await flushPromises()
		expect(w.vm.templates).toEqual([])
		expect(w.vm.variables).toEqual({ case: ['x'] })

		fetchMock.mockImplementation(() => Promise.reject(new Error('offline')))
		await w.vm.load()
		expect(w.vm.loading).toBe(false)
		expect(w.vm.variables).toEqual({ case: ['x'] })
	})

	it('reads nothing without a case type', async () => {
		mount(EmailTemplateAdmin, { props: { caseTypeId: null } })
		await flushPromises()
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('opens a template with every missing field emptied', async () => {
		const w = mount(EmailTemplateAdmin, { props: { caseTypeId: 'ct-1' } })
		await flushPromises()
		w.vm.activeField = 'subject'
		w.vm.selectTemplate({ name: 'Besluit', body: '', extra: 'x' })

		expect(w.vm.draft).toEqual({ id: null, name: 'Besluit', subject: '', body: '', moment: null, channel: null })
		expect(Object.keys(w.vm.draft)).toEqual(['id', 'name', 'subject', 'body', 'moment', 'channel'])
		expect(w.vm.editing).toBe(true)
		expect(w.vm.activeField).toBe('body')
	})

	it('creates a new template with a POST, then closes the editor and reloads', async () => {
		const w = mount(EmailTemplateAdmin, { props: { caseTypeId: 'ct 1' } })
		await flushPromises()
		w.vm.startCreate()
		w.vm.draft.name = 'Ontvangst'
		w.vm.draft.channel = 'email'
		fetchMock.mockClear()

		await w.vm.save()

		const [url, init] = fetchMock.mock.calls[0]
		expect(url).toBe('/apps/dossiq/api/casetypes/ct%201/email-templates')
		expect(init.method).toBe('POST')
		expect(init.headers).toEqual({ 'Content-Type': 'application/json', requesttoken: 'tok' })
		expect(init.body).toBe(JSON.stringify({ name: 'Ontvangst', subject: '', body: '', moment: null, channel: 'email' }))
		expect(fetchMock).toHaveBeenCalledTimes(3)
		expect(w.vm.editing).toBe(false)
		expect(w.vm.saving).toBe(false)
	})

	it('stays in the editor when saving fails', async () => {
		const w = mount(EmailTemplateAdmin, { props: { caseTypeId: 'ct-1' } })
		await flushPromises()
		w.vm.startCreate()
		fetchMock.mockImplementation(() => Promise.reject(new Error('offline')))

		await expect(w.vm.save()).rejects.toThrow('offline')
		expect(w.vm.editing).toBe(true)
		expect(w.vm.saving).toBe(false)
	})
})

