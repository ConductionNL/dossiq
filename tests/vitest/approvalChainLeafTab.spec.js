// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * dossiq consumes decidiq's approval-chain leaf, and says so when it cannot.
 *
 * 🔴 THE ABSENT CASE IS DRIVEN FIRST, because that is the state every instance
 * without decidiq is in, and because the failure it guards is quiet: a wrapper
 * that rendered an empty timeline would be saying "nobody has approved
 * anything", which is a claim about the document that nobody made.
 *
 * 🔑 THE LEAF IS RESOLVED AT RENDER TIME. decidiq registers through a global
 * init script that loads on every page, so the registry is not populated when
 * this bundle is evaluated. A wrapper that read the registry once, at import,
 * would find nothing on a page where the leaf is present, and the notice would
 * be permanent.
 *
 * @spec openspec/changes/approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md
 */

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import ApprovalChainLeafTab from '../../src/components/tabs/ApprovalChainLeafTab.vue'

/** The context a document record hands the leaf. */
const CONTEXT = {
	objectId: 'io-1',
	register: 'dossiq',
	schema: 'informatieobject',
	title: 'Concept brief',
}

/**
 * Put a leaf registry on the page, or take it away.
 *
 * @param {object|null} entry The entry `get()` answers with, or null for none.
 * @return {void}
 */
function withRegistry(entry) {
	if (entry === null) {
		delete window.OCA
		return
	}

	window.OCA = {
		OpenRegister: {
			integrations: {
				get: (id) => (id === 'decidiq-approval-chain' ? entry : undefined),
			},
		},
	}
}

/**
 * Mount the wrapper with the shared stubs.
 *
 * @return {object} The wrapper.
 */
function mountTab() {
	return mount(ApprovalChainLeafTab, {
		props: CONTEXT,
		global: {
			stubs: {
				CnLeafMountHost: {
					name: 'CnLeafMountHost',
					props: ['provider', 'mountProps'],
					template: '<div class="mount-host" />',
				},
				NcEmptyContent: {
					name: 'NcEmptyContent',
					props: ['name', 'description'],
					template:
						'<div class="empty">{{ name }} {{ description }}</div>',
				},
				CheckDecagramOutline: true,
			},
		},
	})
}

afterEach(() => {
	delete window.OCA
	vi.restoreAllMocks()
})

describe('decidiq is not installed', () => {
	it('says the approval chain is unavailable rather than drawing an empty timeline', () => {
		withRegistry(null)
		const wrapper = mountTab()

		expect(wrapper.find('.empty').exists()).toBe(true)
		expect(wrapper.text()).toContain('Approval chain unavailable')
		expect(
			wrapper.find('.mount-host').exists(),
			'the mount host rendered with no leaf behind it, so the document shows a timeline of nothing',
		).toBe(false)
	})

	it('says the same when the registry is there but the leaf is not', () => {
		window.OCA = { OpenRegister: { integrations: { get: () => undefined } } }
		const wrapper = mountTab()

		expect(wrapper.find('.empty').exists()).toBe(true)
	})

	it('does not crash on a registry object that has no get()', () => {
		window.OCA = { OpenRegister: { integrations: {} } }

		expect(() => mountTab()).not.toThrow()
	})
})

describe('decidiq is installed', () => {
	it('hands a mount-mode leaf to the mount host with the document context', () => {
		withRegistry({
			id: 'decidiq-approval-chain',
			renderMode: 'mount',
			mount: () => {},
			unmount: () => {},
		})
		const wrapper = mountTab()

		const host = wrapper.findComponent({ name: 'CnLeafMountHost' })
		expect(
			host.exists(),
			'decidiq declares renderMode mount, so this is the branch that renders',
		).toBe(true)

		const props = host.props('mountProps')
		expect(props.objectId).toBe('io-1')
		expect(
			props.schema,
			'the leaf was given the case schema rather than the document one, so the route would hang off the wrong object',
		).toBe('informatieobject')
		expect(props.register).toBe('dossiq')
		expect(props.integrationId).toBe('decidiq-approval-chain')
		expect(
			props.surface,
			'the surface decides which of decidiq roots it mounts: the widget carries the actions, the timeline does not',
		).toBe('detail-page')
	})

	it('renders a component-mode leaf through its own tab component', () => {
		withRegistry({
			id: 'decidiq-approval-chain',
			renderMode: 'component',
			tab: { name: 'DecidiqTab', template: '<div class="decidiq-tab" />' },
		})
		const wrapper = mountTab()

		expect(wrapper.find('.decidiq-tab').exists()).toBe(true)
		expect(wrapper.find('.empty').exists()).toBe(false)
	})

	it('falls back to the notice for a mount leaf that ships no mount function', () => {
		withRegistry({ id: 'decidiq-approval-chain', renderMode: 'mount' })
		const wrapper = mountTab()

		expect(
			wrapper.find('.empty').exists(),
			'a half-registered leaf rendered a host that can never mount anything',
		).toBe(true)
	})
})
