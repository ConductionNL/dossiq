// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The app shell (src/App.vue): what it hands CnAppRoot, and the two pieces of
 * behaviour it owns itself.
 *
 *  - The permissions CnAppRoot gates pages and menu entries on. An unknown
 *    role from the server must not become a permission, and only an
 *    administrator gets `admin`.
 *  - `#user-settings` opens the settings dialog, once, and is then removed
 *    from the address so a reload does not open it again. The listener goes
 *    with the component.
 *  - The host-mounted object sidebar only renders while a detail page has
 *    published an object into the shared sidebar state.
 *
 * CnAppRoot itself is the library's and is replaced by a stub that records
 * its props and renders the slots App fills.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const state = vi.hoisted(() => ({ values: {} }))

vi.mock('@nextcloud/initial-state', () => ({
	loadState: vi.fn((app, key, fallback) => (key in state.values ? state.values[key] : fallback)),
}))

vi.mock('../../src/store/store.js', () => ({
	initializeStores: vi.fn(async () => undefined),
}))

vi.mock('../../src/views/settings/UserSettings.vue', () => ({
	default: { name: 'UserSettings', render: () => null },
}))

vi.mock('@conduction/nextcloud-vue', async () => {
	const vue = await import('vue')
	const CnAppRoot = vue.defineComponent({
		name: 'CnAppRoot',
		props: [
			'aiCompanion', 'manifest', 'registry', 'pageTypes', 'formatters', 'appId', 'translate',
			'notificationLabels', 'permissions', 'supportDialog', 'userSettingsSections', 'userSettingsNextcloudLinks',
		],
		setup() {
			return { sidebarState: vue.inject('objectSidebarState') }
		},
		data() {
			return { userSettingsOpen: false, pageSidebar: null }
		},
		render() {
			return vue.h('div', { class: 'app-root' }, [
				this.$slots['user-settings-sections']?.(),
				this.$slots.sidebar?.({ pageSidebarComponent: this.pageSidebar }),
			])
		},
	})
	const CnObjectSidebar = vue.defineComponent({
		name: 'CnObjectSidebar',
		props: ['useRegistry', 'title', 'subtitle', 'objectType', 'objectId', 'register', 'schema', 'objectSchema', 'objectData', 'tabs', 'hiddenTabs', 'open'],
		emits: ['update:open'],
		render: () => vue.h('aside', { class: 'object-sidebar' }),
	})
	return { CnAppRoot, CnObjectSidebar }
})

const { default: App } = await import('../../src/App.vue')
const { initializeStores } = await import('../../src/store/store.js')

const manifest = { version: '1.0.0', pages: [] }
const registry = { Thing: { kind: 'page', component: {} } }

/**
 * Mount the shell.
 *
 * @return {import('@vue/test-utils').VueWrapper} The wrapper.
 */
function mountApp() {
	return mount(App, { props: { manifest, registry }, attachTo: document.body })
}

/**
 * The stubbed CnAppRoot inside a wrapper.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The wrapper.
 * @return {import('@vue/test-utils').VueWrapper} The root.
 */
function root(wrapper) {
	return wrapper.findComponent({ name: 'CnAppRoot' })
}

describe('the app shell', () => {
	let wrapper = null

	beforeEach(() => {
		state.values = {}
		delete window.OC
		window.history.replaceState(null, '', '/apps/dossiq/')
		vi.mocked(initializeStores).mockClear()
	})

	afterEach(() => {
		wrapper?.unmount()
		wrapper = null
	})

	it('hands CnAppRoot the manifest, registry and app id, and initialises the stores once', async () => {
		wrapper = mountApp()
		await flushPromises()

		const props = root(wrapper).props()
		expect(props.manifest).toEqual(manifest)
		expect(props.registry).toEqual(registry)
		expect(props.appId).toBe('dossiq')
		expect(props.aiCompanion).toBe(true)
		expect(props.translate('Cases')).toBe('Cases')
		expect(props.userSettingsSections).toEqual(['views', 'app', 'credentials', 'walkthrough', 'integrations'])
		expect(props.userSettingsNextcloudLinks.map((link) => link.section)).toEqual([
			'personal-info', 'security', 'notifications', 'sync-clients', 'sharing', 'theming', 'availability', 'privacy',
		])
		expect(initializeStores).toHaveBeenCalledTimes(1)
	})

	it('grants a user only the known roles the server names', () => {
		state.values.role_permissions = ['privacy', 'superpowers']
		wrapper = mountApp()

		expect(root(wrapper).props('permissions')).toEqual(['user', 'privacy'])
	})

	it('grants an administrator admin and every role', () => {
		window.OC = { isUserAdmin: () => true }
		wrapper = mountApp()

		expect(root(wrapper).props('permissions')).toEqual(['user', 'admin', 'privacy', 'sociaal-domein'])
	})

	it('holds the support dialog back only on a definite not-seen walkthrough', () => {
		wrapper = mountApp()
		expect(root(wrapper).props('supportDialog')).toBe(true)
		wrapper.unmount()

		state.values.walkthrough_seen = false
		wrapper = mountApp()
		expect(root(wrapper).props('supportDialog')).toBe(false)
	})

	it('opens the user settings for #user-settings on load and removes the hash', () => {
		window.history.replaceState(null, '', '/apps/dossiq/?tab=1#user-settings')

		wrapper = mountApp()

		expect(root(wrapper).vm.userSettingsOpen).toBe(true)
		expect(window.location.hash).toBe('')
		expect(window.location.pathname + window.location.search).toBe('/apps/dossiq/?tab=1')
	})

	it('opens them on a later hash change, ignores any other hash, and stops listening when unmounted', () => {
		window.history.replaceState(null, '', '/apps/dossiq/#cases')
		wrapper = mountApp()
		const appRoot = root(wrapper).vm
		expect(appRoot.userSettingsOpen).toBe(false)
		expect(window.location.hash).toBe('#cases')

		window.history.replaceState(null, '', '/apps/dossiq/#user-settings')
		window.dispatchEvent(new HashChangeEvent('hashchange'))
		expect(appRoot.userSettingsOpen).toBe(true)
		expect(window.location.hash).toBe('')

		appRoot.userSettingsOpen = false
		wrapper.unmount()
		wrapper = null
		window.history.replaceState(null, '', '/apps/dossiq/#user-settings')
		window.dispatchEvent(new HashChangeEvent('hashchange'))
		expect(appRoot.userSettingsOpen).toBe(false)
		expect(window.location.hash).toBe('#user-settings')
	})

	it('mounts the object sidebar only while a detail page has published an object', async () => {
		wrapper = mountApp()
		expect(wrapper.findComponent({ name: 'CnObjectSidebar' }).exists()).toBe(false)

		const shared = root(wrapper).vm.sidebarState
		Object.assign(shared, {
			active: true,
			title: 'Case 12',
			objectId: 'abc',
			schemaObject: { title: 'Case' },
			object: { id: 'abc' },
		})
		await wrapper.vm.$nextTick()

		const sidebar = wrapper.findComponent({ name: 'CnObjectSidebar' })
		expect(sidebar.exists()).toBe(true)
		expect(sidebar.props('useRegistry')).toBe(false)
		expect(sidebar.props('title')).toBe('Case 12')
		expect(sidebar.props('objectSchema')).toEqual({ title: 'Case' })
		expect(sidebar.props('objectData')).toEqual({ id: 'abc' })
		expect(sidebar.props('open')).toBe(true)

		sidebar.vm.$emit('update:open', false)
		await wrapper.vm.$nextTick()
		expect(shared.open).toBe(false)
	})

	it('renders the page sidebar component the root passes to the sidebar slot', async () => {
		wrapper = mountApp()
		const PageSidebar = defineComponent({ name: 'PageSidebar', render: () => h('nav', 'flow') })

		root(wrapper).vm.pageSidebar = PageSidebar
		await wrapper.vm.$nextTick()

		expect(wrapper.findComponent({ name: 'PageSidebar' }).exists()).toBe(true)
	})
})
