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
})
