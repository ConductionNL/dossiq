// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ContactMomentDetailDialog: how a contact moment's times, codes and case
 * reference are put into words (REQ-CRS-04).
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/party-pages/spec.md#requirement-the-contact-page-lists-the-contacts-documents-and-communication-req-crs-04
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ContactMomentDetailDialog from '../../src/dialogs/ContactMomentDetailDialog.vue'

const STUBS = {
	NcDialog: { name: 'NcDialog', props: ['name'], template: '<div><h2>{{ name }}</h2><slot /><slot name="actions" /></div>' },
	NcButton: { props: ['href'], emits: ['click'], template: '<a v-if="href" :href="href"><slot /></a><button v-else @click="$emit(\'click\')"><slot /></button>' },
}

/**
 * Mount the dialog over one row.
 *
 * @param {object|null} row The contact moment.
 * @return {object} The wrapper.
 */
function open(row) {
	return mount(ContactMomentDetailDialog, { props: { row }, global: { stubs: STUBS } })
}

/**
 * The text of one fact, or null when the dialog leaves it out.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} key The fact key.
 * @return {string|null}
 */
function fact(wrapper, key) {
	const found = wrapper.find(`[data-testid="contact-moment-detail-${key}"]`)
	return found.exists() ? found.text() : null
}

describe('ContactMomentDetailDialog facts', () => {
	it('writes the start and end time in the reader\'s locale', () => {
		const wrapper = open({ startTime: '2026-03-02T09:15:00Z', endTime: '2026-03-02T09:40:00Z' })

		expect(fact(wrapper, 'startTime')).toBe(new Date('2026-03-02T09:15:00Z').toLocaleString())
		expect(fact(wrapper, 'endTime')).toBe(new Date('2026-03-02T09:40:00Z').toLocaleString())
	})

	it('shows a time that does not parse as it was recorded', () => {
		expect(fact(open({ startTime: 'yesterday morning' }), 'startTime')).toBe('yesterday morning')
	})

	it('words every stored channel and both directions', () => {
		const words = { email: 'Email', webformulier: 'Web form', chat: 'Chat', social_media: 'Social media', balie: 'Desk', brief: 'Letter' }
		for (const [code, label] of Object.entries(words)) {
			expect(fact(open({ notificationChannel: code }), 'channel')).toBe(label)
		}
		expect(fact(open({ direction: 'outbound' }), 'direction')).toBe('Outbound')
	})

	it('keeps a code it has no words for', () => {
		const wrapper = open({ notificationChannel: 'pigeon', direction: 'sideways' })

		expect(fact(wrapper, 'channel')).toBe('pigeon')
		expect(fact(wrapper, 'direction')).toBe('sideways')
	})

	it('lists the facts in reading order and leaves out blank ones', () => {
		const wrapper = open({ subject: 'Permit', summary: '   ', message: 'Called back', note: 'Prefers mail', direction: 'inbound' })

		expect(wrapper.findAll('dd').map((dd) => dd.attributes('data-testid'))).toEqual([
			'contact-moment-detail-direction',
			'contact-moment-detail-subject',
			'contact-moment-detail-message',
			'contact-moment-detail-note',
		])
	})

	it('shows the empty notice for no row at all', () => {
		expect(open(null).find('[data-testid="contact-moment-detail-empty"]').exists()).toBe(true)
	})
})

describe('ContactMomentDetailDialog case link', () => {
	it('opens a case given as an object, by its id', () => {
		expect(open({ summary: 'x', case: { id: 'case 7' } }).find('a').attributes('href')).toBe('/index.php/apps/dossiq/cases/case%207')
	})

	it('falls back to the object\'s @self id', () => {
		expect(open({ summary: 'x', case: { '@self': { id: 'c-8' } } }).find('a').attributes('href')).toBe('/index.php/apps/dossiq/cases/c-8')
	})

	it('offers no link for a case reference without an id', () => {
		expect(open({ summary: 'x', case: {} }).find('a').exists()).toBe(false)
		expect(open({ summary: 'x', case: null }).find('a').exists()).toBe(false)
		expect(open({ summary: 'x', case: '' }).find('a').exists()).toBe(false)
	})
})
