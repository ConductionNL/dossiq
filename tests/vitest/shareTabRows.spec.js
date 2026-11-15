// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The share tab's three lists: access links with their state, what the
 * holder may do and the actions that state allows; partner shares with their
 * permission; federated shares with their fields and status; each with its
 * loading and empty text, and every button emitting its event.
 *
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', props: ['variant', 'type'], emits: ['click'], template: '<button :data-variant="variant || type" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span class="loading-icon" />' },
}))

const { default: ShareTab } = await import('../../src/views/cases/components/ShareTab.vue')

/**
 * Mount the tab.
 *
 * @param {object} props Its props.
 * @return {object} The wrapper.
 */
function mountTab(props = {}) {
	return mount(ShareTab, { props })
}

/**
 * The item of a list whose label reads the given text.
 *
 * @param {object} wrapper The tab.
 * @param {string} label The label text.
 * @return {object} The list item.
 */
function itemLabelled(wrapper, label) {
	return wrapper.findAll('.share-tab__item').find((item) => item.find('.share-tab__label').text() === label)
}

/**
 * The button of an item whose text reads the given text.
 *
 * @param {object} item The list item.
 * @param {string} text The button text.
 * @return {object|undefined} The button.
 */
function buttonOf(item, text) {
	return item.findAll('button').find((button) => button.text() === text)
}

const links = [
	{ id: 'l-1', state: 'live', label: 'Buurman', capabilities: 'read, comment,upload', createdBy: 'alice', expiresAt: '2026-12-01', advisoryBody: 'Welstand' },
	{ id: 'l-2', state: 'paused', label: '', capabilities: '', createdBy: 'bob' },
	{ id: 'l-3', state: 'expired', label: 'Oud', capabilities: 'read,review', createdBy: 'carol' },
	{ id: 'l-4', state: 'revoked', label: 'Ingetrokken', capabilities: 'read', createdBy: 'dave' },
	{ id: 'l-5', state: 'odd', label: 'Vreemd', capabilities: 'read', createdBy: 'eve' },
]

describe('ShareTab links', () => {
	it('names each link state, what the holder may do and who made it', () => {
		const wrapper = mountTab({ links })

		const live = itemLabelled(wrapper, 'Buurman')
		expect(live.find('.share-tab__type-badge').text()).toBe('Open')
		expect(live.find('.share-tab__type-badge').classes()).toContain('share-tab__type-badge--live')
		expect(live.find('.share-tab__item-details').text()).toContain('The holder may read, comment, add a document')
		expect(live.find('.share-tab__item-details').text()).toContain('Created by alice')
		expect(live.find('.share-tab__item-details').text()).toContain('Stops working on 2026-12-01')
		expect(live.find('.share-tab__item-details').text()).toContain('Asked for advice from Welstand')

		const paused = itemLabelled(wrapper, 'Unnamed link')
		expect(paused.find('.share-tab__type-badge').text()).toBe('Switched off')
		expect(paused.find('.share-tab__item-details').text()).toContain('The holder may read')
		expect(paused.find('.share-tab__item-details').text()).not.toContain('Stops working')

		expect(itemLabelled(wrapper, 'Oud').find('.share-tab__type-badge').text()).toBe('Expired')
		expect(itemLabelled(wrapper, 'Oud').find('.share-tab__item-details').text()).toContain('The holder may read, review')
		expect(itemLabelled(wrapper, 'Ingetrokken').find('.share-tab__type-badge').text()).toBe('Revoked')
		expect(itemLabelled(wrapper, 'Vreemd').find('.share-tab__type-badge').text()).toBe('odd')
	})

	it('offers switching off, back on and revoking only where the state allows', () => {
		const wrapper = mountTab({ links })

		expect(itemLabelled(wrapper, 'Buurman').findAll('button').map((b) => b.text())).toEqual(['See what they see', 'Switch off', 'Revoke'])
		expect(itemLabelled(wrapper, 'Unnamed link').findAll('button').map((b) => b.text())).toEqual(['See what they see', 'Switch back on', 'Revoke'])
		expect(itemLabelled(wrapper, 'Oud').findAll('button').map((b) => b.text())).toEqual(['See what they see', 'Revoke'])
		expect(itemLabelled(wrapper, 'Ingetrokken').findAll('button').map((b) => b.text())).toEqual(['See what they see'])
	})

	it('emits the link for preview, pause and revoke', async () => {
		const wrapper = mountTab({ links })
		const live = itemLabelled(wrapper, 'Buurman')

		await buttonOf(live, 'See what they see').trigger('click')
		await buttonOf(live, 'Switch off').trigger('click')
		await buttonOf(live, 'Revoke').trigger('click')

		expect(wrapper.emitted('previewLink')).toEqual([[links[0]]])
		expect(wrapper.emitted('pauseLink')).toEqual([[links[0]]])
		expect(wrapper.emitted('revokeLink')).toEqual([[links[0]]])
	})

	it('says when links are loading or there are none', () => {
		expect(mountTab({ linksLoading: true }).text()).toContain('Loading links')
		expect(mountTab().text()).toContain('Nobody outside can open this case yet.')
	})
})

describe('ShareTab partner and federated shares', () => {
	it('names each partner share permission and revokes by id', async () => {
		const wrapper = mountTab({
			shares: [
				{ id: 's-1', label: 'Provincie', permissionLevel: 'bekijken' },
				{ id: 's-2', label: '', permissionLevel: 'bekijken_reageren' },
				{ id: 's-3', label: 'Waterschap', permissionLevel: 'bekijken_bijdragen' },
				{ id: 's-4', label: 'Anders', permissionLevel: 'eigen' },
			],
		})

		expect(itemLabelled(wrapper, 'Provincie').find('.share-tab__item-details').text()).toBe('View only')
		expect(itemLabelled(wrapper, 'Unnamed share').find('.share-tab__item-details').text()).toBe('View + Comment')
		expect(itemLabelled(wrapper, 'Waterschap').find('.share-tab__item-details').text()).toBe('View + Contribute')
		expect(itemLabelled(wrapper, 'Anders').find('.share-tab__item-details').text()).toBe('eigen')

		await buttonOf(itemLabelled(wrapper, 'Waterschap'), 'Revoke').trigger('click')
		expect(wrapper.emitted('revoke')).toEqual([['s-3']])
	})

	it('lists federated shares with their fields and status, revoking only live ones', async () => {
		const wrapper = mountTab({
			federatedShares: [
				{ id: 'f-1', remoteCloudId: 'gemeente@remote.example', sharedFields: ['title', 'status'], status: 'active' },
				{ id: 'f-2', remoteCloudId: 'oud@remote.example', status: 'revoked' },
			],
		})

		const active = itemLabelled(wrapper, 'gemeente@remote.example')
		expect(active.find('.share-tab__item-details').text()).toContain('Shared fields: title, status')
		expect(active.find('.share-tab__item-details').text()).toContain('Status: active')
		expect(itemLabelled(wrapper, 'oud@remote.example').find('.share-tab__item-details').text()).toContain('Shared fields: ')
		expect(itemLabelled(wrapper, 'oud@remote.example').findAll('button').map((b) => b.text())).toEqual(['Activity'])

		await buttonOf(active, 'Activity').trigger('click')
		await buttonOf(active, 'Revoke').trigger('click')
		expect(wrapper.emitted('open-activity')).toEqual([['f-1']])
		expect(wrapper.emitted('revoke-federated')).toEqual([['f-1']])
	})

	it('says when shares are loading or there are none', () => {
		expect(mountTab({ loading: true, federatedLoading: true }).text()).toContain('Loading shares...')
		expect(mountTab({ loading: true, federatedLoading: true }).text()).toContain('Loading federated shares...')
		const empty = mountTab().text()
		expect(empty).toContain('This case has not been shared with a partner yet.')
		expect(empty).toContain('This case has not been shared with a remote organisation yet.')
	})

	it('emits each create and transfer action', async () => {
		const wrapper = mountTab()
		const button = (text) => wrapper.findAll('.share-tab__actions button').find((b) => b.text() === text)

		await button('Share by link').trigger('click')
		await button('Share with partner').trigger('click')
		await button('Transfer case').trigger('click')
		await button('Share with remote organisation').trigger('click')

		expect(Object.keys(wrapper.emitted())).toEqual(expect.arrayContaining(['createLink', 'create-partner-share', 'transfer-case', 'create-federated-share']))
		expect(wrapper.emitted('createLink')).toHaveLength(1)
		expect(wrapper.emitted('transfer-case')).toHaveLength(1)
	})
})
