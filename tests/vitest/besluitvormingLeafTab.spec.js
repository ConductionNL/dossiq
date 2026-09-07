// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Besluitvorming sidebar tab must render decidiq's leaf in the mode
 * decidiq registers it in.
 *
 * The defect this pins down: decidiq registers `decidesk-decisions` as a
 * MOUNT-mode leaf (`renderMode: "mount"`, `mount()` / `unmount()`, no
 * `tab`), and this tab only ever read `entry.tab`. With decidiq installed
 * and its "Decisions" body tab working, the sidebar tab still said the app
 * was not installed, and the copy named decidesk. Three states are covered:
 * a component leaf, a mount leaf, and no leaf at all.
 *
 * @spec openspec/changes/consume-decidesk-besluitvorming-leaf/tasks.md
 */
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnLeafMountHost: defineComponent({
		name: 'CnLeafMountHost',
		props: ['provider', 'mountProps'],
		render() {
			return h('div', {
				class: 'leaf-mount-host-stub',
				'data-provider-id': this.provider && this.provider.id,
				'data-object-id': this.mountProps && this.mountProps.objectId,
				'data-surface': this.mountProps && this.mountProps.surface,
			})
		},
	}),
}))

vi.mock('@nextcloud/vue', () => ({
	NcEmptyContent: defineComponent({
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		render() {
			return h('div', { class: 'empty-content-stub' }, [
				h('h2', this.name),
				h('p', this.description),
			])
		},
	}),
}))

const { default: BesluitvormingLeafTab } =
	await import('../../src/components/tabs/BesluitvormingLeafTab.vue')

const ComponentLeaf = defineComponent({
	name: 'ComponentLeaf',
	props: ['objectId'],
	render() {
		return h('div', { class: 'component-leaf-stub' }, this.objectId)
	},
})

/**
 * Install a fake OpenRegister integration registry answering one entry.
 *
 * @param {object|undefined} entry What `registry.get('decidesk-decisions')` returns.
 * @return {void}
 */
function registry(entry) {
	window.OCA = {
		OpenRegister: {
			integrations: {
				get: (id) => (id === 'decidesk-decisions' ? entry : undefined),
			},
		},
	}
}

const PROPS = {
	objectId: 'case-1',
	register: 'dossiq',
	schema: 'case',
	title: 'Case 1',
}

describe('BesluitvormingLeafTab', () => {
	afterEach(() => {
		delete window.OCA
	})

	it('renders a component leaf through its tab component', () => {
		registry({ id: 'decidesk-decisions', tab: ComponentLeaf })
		const wrapper = mount(BesluitvormingLeafTab, { props: PROPS })
		expect(wrapper.find('.component-leaf-stub').text()).toBe('case-1')
		expect(wrapper.find('.leaf-mount-host-stub').exists()).toBe(false)
		expect(wrapper.find('.empty-content-stub').exists()).toBe(false)
	})

	it('renders a mount-mode leaf through CnLeafMountHost with the case context', () => {
		registry({
			id: 'decidesk-decisions',
			renderMode: 'mount',
			tab: null,
			widget: null,
			mount: () => {},
			unmount: () => {},
		})
		const wrapper = mount(BesluitvormingLeafTab, { props: PROPS })
		const host = wrapper.find('.leaf-mount-host-stub')
		expect(host.exists()).toBe(true)
		expect(host.attributes('data-provider-id')).toBe('decidesk-decisions')
		expect(host.attributes('data-object-id')).toBe('case-1')
		expect(host.attributes('data-surface')).toBe('sidebar-tab')
		expect(wrapper.find('.empty-content-stub').exists()).toBe(false)
	})

	it('names decidiq, not decidesk, when no leaf is registered', () => {
		registry(undefined)
		const wrapper = mount(BesluitvormingLeafTab, { props: PROPS })
		const empty = wrapper.find('.empty-content-stub')
		expect(empty.exists()).toBe(true)
		expect(empty.text()).toContain('decidiq')
		expect(empty.text()).not.toContain('decidesk')
	})
})
