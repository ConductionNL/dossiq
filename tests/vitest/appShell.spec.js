// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * src/App.vue, the app shell as it runs: what it hands CnAppRoot (the
 * support note from the initial state, the permissions, the notification
 * labels, the user settings sections and Nextcloud links, the app-bound
 * translate), the sidebar state it provides under both names, the
 * host-mounted object sidebar, the page's own sidebar component, the store
 * boot, and the `#user-settings` hash opening the user settings dialog on
 * load and on a later hash change, but not after unmount.
 *
 * CnAppRoot and CnObjectSidebar are stand-ins that record their props and
 * render the slots; the initial state and the store boot are doubled. The
 * app's own utils run for real.
 *
 * @spec openspec/specs/personal-settings-surface/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h, inject } from 'vue'

const shell = vi.hoisted(() => ({ states: {}, storeBoots: 0, rootInstance: null }))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnAppRoot: {
		name: 'CnAppRoot',
		props: [
			'aiCompanion', 'manifest', 'registry', 'pageTypes', 'formatters', 'appId', 'translate',
			'notificationLabels', 'permissions', 'supportDialog', 'userSettingsSections',
			'userSettingsNextcloudLinks',
		],
		data: () => ({ userSettingsOpen: false }),
		created() {
			shell.rootInstance = this
		},
		render() {
			return h('div', { class: 'root' }, [
				this.$slots['user-settings-sections']?.(),
				this.$slots.sidebar?.({ pageSidebarComponent: shell.pageSidebar }),
			])
		},
	},
	CnObjectSidebar: {
		name: 'CnObjectSidebar',
		props: [
			'useRegistry', 'title', 'subtitle', 'objectType', 'objectId', 'register', 'schema',
			'objectSchema', 'objectData', 'tabs', 'hiddenTabs', 'open',
		],
		emits: ['update:open'],
		render: () => h('aside', { class: 'object-sidebar' }),
	},
}))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => (key in shell.states ? shell.states[key] : fallback),
}))
vi.mock('../../src/store/store.js', () => ({
	initializeStores: async () => {
		shell.storeBoots++
	},
}))
vi.mock('../../src/views/settings/UserSettings.vue', () => ({
	default: { name: 'UserSettings', render: () => h('section', { class: 'user-settings' }) },
}))

const { default: App } = await import('../../src/App.vue')
const { currentPermissions } = await import('../../src/utils/permissions.js')
const { notificationLabels } = await import('../../src/utils/notificationLabels.js')

const mounted = []

/**
 * Mount the shell with a manifest and whatever extra props a test needs.
 *
 * @param {object} props Extra props.
 * @return {object} The wrapper.
 */
function mountApp(props = {}) {
	const wrapper = mount(App, { props: { manifest: { pages: [] }, ...props } })
	mounted.push(wrapper)
	return wrapper
}

/** @return {object} The CnAppRoot stand-in's props. */
function rootProps(wrapper) {
	return wrapper.findComponent({ name: 'CnAppRoot' }).props()
}

beforeEach(() => {
	shell.states = {}
	shell.storeBoots = 0
	shell.pageSidebar = undefined
	shell.rootInstance = null
	window.history.replaceState(null, '', '/apps/dossiq/cases?tab=open')
	delete window.OC
})

afterEach(() => {
	while (mounted.length) {
		const w = mounted.pop()
		if (w.vm) {
			w.unmount()
		}
	}
})

describe('what the shell hands CnAppRoot', () => {
	it('forwards the manifest, registry, page types and formatters, bound to the dossiq app id', () => {
		const registry = { CaseList: { kind: 'page' } }
		const pageTypes = { index: 'IndexType' }
		const formatters = { date: () => 'x' }
		const wrapper = mountApp({ registry, pageTypes, formatters })

		const props = rootProps(wrapper)
		expect(props.manifest).toEqual({ pages: [] })
		expect(props.registry).toEqual(registry)
		expect(props.pageTypes).toEqual(pageTypes)
		expect(props.formatters).toEqual(formatters)
		expect(props.appId).toBe('dossiq')
		expect(props.aiCompanion).toBe(true)
	})

	it('defaults the registry, page types and formatters to empty maps', () => {
		const props = rootProps(mountApp())
		expect([props.registry, props.pageTypes, props.formatters]).toEqual([{}, {}, {}])
	})

	it('translates through the dossiq catalogue', () => {
		const translate = rootProps(mountApp()).translate
		expect(translate('Cases')).toBe('Cases')
		expect(translate('{count} open', undefined)).toBe('{count} open')
	})

	it('lists the user settings sections in order and the eight Nextcloud links', () => {
		const props = rootProps(mountApp())
		expect(props.userSettingsSections).toEqual(['views', 'app', 'credentials', 'walkthrough', 'integrations'])
		expect(props.userSettingsNextcloudLinks.map((l) => l.section)).toEqual([
			'personal-info', 'security', 'notifications', 'sync-clients', 'sharing', 'theming', 'availability', 'privacy',
		])
		expect(props.userSettingsNextcloudLinks[0]).toEqual({ label: 'Personal info', section: 'personal-info', icon: 'AccountOutline' })
	})

	it('passes the permissions of the current user', () => {
		window.OC = { isUserAdmin: () => true }
		const props = rootProps(mountApp())
		expect(props.permissions).toEqual(currentPermissions())
		expect(props.permissions.slice(0, 2)).toEqual(['user', 'admin'])
	})

	it('passes an ordinary account only the user permission plus its roles', () => {
		shell.states.role_permissions = []
		expect(rootProps(mountApp()).permissions).toEqual(['user'])
	})

	it('passes the notification rule labels', () => {
		expect(rootProps(mountApp()).notificationLabels).toEqual(notificationLabels())
	})
})

describe('the support note', () => {
	it('shows when the state says nothing either way', () => {
		expect(rootProps(mountApp()).supportDialog).toBe(true)
	})

	it('waits while the welcome tour is not yet behind the user', () => {
		shell.states.walkthrough_seen = false
		expect(rootProps(mountApp()).supportDialog).toBe(false)
	})

	it('stays off when an administrator switched it off', () => {
		shell.states.walkthrough_seen = true
		shell.states.support_note_shown = false
		expect(rootProps(mountApp()).supportDialog).toBe(false)
	})
})

describe('boot and slots', () => {
	it('brings the stores up once', async () => {
		mountApp()
		await flushPromises()
		expect(shell.storeBoots).toBe(1)
	})

	it('renders the app\'s user settings into the dialog slot', () => {
		expect(mountApp().find('section.user-settings').exists()).toBe(true)
	})

	it('provides one sidebar state under both names, inactive and open by default', () => {
		let seen
		const Probe = {
			setup() {
				seen = [inject('objectSidebarState'), inject('sidebarState')]
				return () => null
			},
		}
		shell.pageSidebar = Probe
		mountApp()
		expect(seen[0]).toBe(seen[1])
		expect(seen[0]).toMatchObject({ active: false, open: true, objectId: '', hiddenTabs: [], tabs: undefined })
	})

	it('mounts the object sidebar only while the state is active, with the resolved schema and record', async () => {
		let state
		shell.pageSidebar = {
			setup() {
				state = inject('objectSidebarState')
				return () => null
			},
		}
		const wrapper = mountApp()
		expect(wrapper.findComponent({ name: 'CnObjectSidebar' }).exists()).toBe(false)

		Object.assign(state, {
			active: true,
			title: 'Case 12',
			objectId: 'c-12',
			register: 'dossiq',
			schema: 'case',
			schemaObject: { title: 'Case' },
			object: { id: 'c-12' },
			tabs: [{ id: 'data' }],
		})
		await flushPromises()

		const sidebar = wrapper.findComponent({ name: 'CnObjectSidebar' })
		expect(sidebar.props()).toMatchObject({
			useRegistry: false,
			title: 'Case 12',
			objectId: 'c-12',
			register: 'dossiq',
			schema: 'case',
			objectSchema: { title: 'Case' },
			objectData: { id: 'c-12' },
			tabs: [{ id: 'data' }],
			open: true,
		})

		sidebar.vm.$emit('update:open', false)
		expect(state.open).toBe(false)
	})

	it('renders the page\'s own sidebar component next to it', () => {
		shell.pageSidebar = { name: 'FlowSidebar', render: () => h('nav', { class: 'flow-sidebar' }) }
		expect(mountApp().find('nav.flow-sidebar').exists()).toBe(true)
	})
})

describe('the #user-settings hash', () => {
	it('opens the user settings dialog on load and drops the hash, keeping path and query', () => {
		window.history.replaceState({ k: 1 }, '', '/apps/dossiq/cases?tab=open#user-settings')
		mountApp()
		expect(shell.rootInstance.userSettingsOpen).toBe(true)
		expect(window.location.hash).toBe('')
		expect(window.location.pathname + window.location.search).toBe('/apps/dossiq/cases?tab=open')
		expect(window.history.state).toEqual({ k: 1 })
	})

	it('leaves the dialog shut and the hash alone for any other hash', () => {
		window.history.replaceState(null, '', '/apps/dossiq/cases#other')
		mountApp()
		expect(shell.rootInstance.userSettingsOpen).toBe(false)
		expect(window.location.hash).toBe('#other')
	})

	it('opens the dialog when the hash changes later', () => {
		mountApp()
		expect(shell.rootInstance.userSettingsOpen).toBe(false)

		window.history.replaceState(null, '', '/apps/dossiq/cases#user-settings')
		window.dispatchEvent(new HashChangeEvent('hashchange'))
		expect(shell.rootInstance.userSettingsOpen).toBe(true)
		expect(window.location.hash).toBe('')
	})

	it('stops listening once the shell is unmounted', () => {
		const wrapper = mountApp()
		const root = shell.rootInstance
		wrapper.unmount()
		mounted.length = 0

		window.history.replaceState(null, '', '/apps/dossiq/cases#user-settings')
		window.dispatchEvent(new HashChangeEvent('hashchange'))
		expect(root.userSettingsOpen).toBe(false)
		expect(window.location.hash).toBe('#user-settings')
	})
})
