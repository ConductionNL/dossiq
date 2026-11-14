// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PublicAppointmentPage: the appointment a public token opens, its status,
 * and cancelling a scheduled one.
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-appointment-booking/tasks.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockGet = vi.fn()
const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (app, text) => text,
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', props: ['type'], emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span class="loading" />' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note"><slot /></div>' },
}))

const { default: PublicAppointmentPage } = await import('../../src/views/public/PublicAppointmentPage.vue')

/**
 * @return {object} The mounted page.
 */
function page() {
	return mount(PublicAppointmentPage, { props: { token: 'tok-1' } })
}

/**
 * @param {object} appointment The appointment the API answers.
 * @return {void}
 */
function answer(appointment) {
	mockGet.mockResolvedValue({ data: { appointment } })
}

beforeEach(() => {
	mockGet.mockReset()
	mockPost.mockReset()
})

describe('PublicAppointmentPage', () => {
	it('loads the appointment by its token, showing the loading icon until it answers', async () => {
		answer({ dateTime: '2026-10-14T10:00:00Z', status: 'completed' })

		const wrapper = page()
		expect(wrapper.find('.loading').exists()).toBe(true)
		await flushPromises()

		expect(mockGet).toHaveBeenCalledWith('/index.php/apps/dossiq/api/public/appointment/tok-1')
		expect(wrapper.find('.loading').exists()).toBe(false)
		expect(wrapper.find('h2').text()).toBe('Your Appointment')
		const details = wrapper.findAll('.public-appointment-page__details p').map((p) => p.text())
		expect(details[0]).toContain('14 oktober 2026')
		expect(details[1]).toBe('Status: Completed')
		expect(wrapper.find('button').exists()).toBe(false)
	})

	it.each([
		['scheduled', 'Scheduled'],
		['cancelled', 'Cancelled'],
		['no_show', 'Not appeared'],
		['moved', 'moved'],
	])('labels the %s status as %s', async (status, label) => {
		answer({ dateTime: null, status })

		const wrapper = page()
		await flushPromises()

		const details = wrapper.findAll('.public-appointment-page__details p').map((p) => p.text())
		expect(details).toEqual(['Date and time: -', `Status: ${label}`])
	})

	it('says the link is invalid when the appointment cannot be read', async () => {
		mockGet.mockRejectedValue(new Error('404'))

		const wrapper = page()
		await flushPromises()

		expect(wrapper.find('h2').text()).toBe('Appointment not found')
		expect(wrapper.find('.public-appointment-page__error p').text()).toBe('This appointment link is invalid or has expired.')
		expect(wrapper.find('.public-appointment-page__content').exists()).toBe(false)
	})

	it('cancels a scheduled appointment and says so', async () => {
		answer({ dateTime: '2026-10-14T10:00:00Z', status: 'scheduled' })
		mockPost.mockResolvedValue({ data: {} })

		const wrapper = page()
		await flushPromises()
		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/index.php/apps/dossiq/api/public/appointment/tok-1/cancel')
		expect(wrapper.find('.note').text()).toBe('Your appointment has been cancelled.')
		expect(wrapper.findAll('.public-appointment-page__details p')[1].text()).toBe('Status: Cancelled')
		expect(wrapper.find('button').exists()).toBe(false)
	})

	it('keeps the appointment scheduled when cancelling fails', async () => {
		answer({ dateTime: '2026-10-14T10:00:00Z', status: 'scheduled' })
		mockPost.mockRejectedValue(new Error('500'))

		const wrapper = page()
		await flushPromises()
		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(wrapper.find('.note').exists()).toBe(false)
		expect(wrapper.findAll('.public-appointment-page__details p')[1].text()).toBe('Status: Scheduled')
		expect(wrapper.find('button').exists()).toBe(true)
	})
})
