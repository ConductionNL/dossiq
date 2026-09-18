// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the applicant sees of a case, and what they must not see.
 *
 * The public status page is the one dossiq surface with no session behind it.
 * Everything on it was decided by the server, and the page's only job is to
 * render what arrived without adding to it.
 *
 * The assertions that earn their place:
 *
 *  - the page renders the entries the payload carries, in the order they
 *    arrived. The server sorted them; a page that re-sorted would be a second
 *    opinion on a list it did not compile;
 *  - it renders the DATE beside each line. "A beschikking was delivered" is not
 *    the answer the applicant came for; "delivered on 4 May" is;
 *  - a payload with no timeline renders no heading. An empty "What has
 *    happened" section reads as a case where nothing has happened, which is a
 *    different and wrong claim from a case whose history is not being shown;
 *  - a timeline that arrives as something other than a list is dropped rather
 *    than rendered. An older OpenRegister answers this endpoint without a
 *    timeline at all, and that instance must show the status page, not a blank
 *    screen.
 *
 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PublicStatusPage from '../../src/views/public/PublicStatusPage.vue'

vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (app, text) => text,
	translatePlural: (app, singular) => singular,
}))

/**
 * Answer the token endpoint with one payload.
 *
 * @param {object} payload What the endpoint returns.
 *
 * @return {void}
 */
function resolvesWith(payload) {
	global.fetch = vi.fn().mockResolvedValue({
		ok: true,
		json: async () => payload,
	})
}

/**
 * Mount the page and let its one request settle.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function page() {
	const wrapper = mount(PublicStatusPage, {
		props: { token: 'tok-1' },
		global: { stubs: { NcLoadingIcon: true } },
	})
	await flushPromises()
	return wrapper
}

describe('the public status page timeline', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('renders each public entry with its date, in the order it arrived', async () => {
		resolvesWith({
			object: { title: 'Kapvergunning', identifier: 'Z-2026-1', status: 'In behandeling' },
			timeline: [
				{
					id: 'e1',
					kind: 'beschikking-verzonden',
					message: 'Beschikking verzonden',
					occurredAt: '2026-05-04T09:12:00+02:00',
				},
				{
					id: 'e2',
					kind: 'statuswijziging',
					message: 'Status: In behandeling',
					occurredAt: '2026-04-28T11:00:00+02:00',
				},
			],
		})

		const wrapper = await page()
		const entries = wrapper.findAll('[data-testid="public-status-timeline-entry"]')

		expect(entries).toHaveLength(2)
		expect(entries[0].text()).toContain('Beschikking verzonden')
		expect(entries[0].text()).toContain('4 mei 2026')
		expect(entries[1].text()).toContain('Status: In behandeling')
	})

	it('shows no heading at all when the case has no public history', async () => {
		resolvesWith({
			object: { title: 'Kapvergunning', identifier: 'Z-2026-1' },
			timeline: [],
		})

		const wrapper = await page()

		expect(wrapper.find('[data-testid="public-status-timeline"]').exists()).toBe(false)
	})

	it('drops a timeline that is not a list, and still shows the status', async () => {
		resolvesWith({
			object: { title: 'Kapvergunning', identifier: 'Z-2026-1', status: 'In behandeling' },
			timeline: 'nope',
		})

		const wrapper = await page()

		expect(wrapper.find('[data-testid="public-status-timeline"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('In behandeling')
	})
})
