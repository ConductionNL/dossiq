// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * src/main.js, the app shell's boot: library registration, the field
 * inspection integration with dossiq's offline schemas, the structure
 * profile's menu layout, the runtime flags from the initial state, the
 * router and the mount on #content, and the boot surviving a failing
 * translation registration or load.
 *
 * The library, vue-router, the initial state, the capabilities and the
 * object store are stand-ins that record what boot asks of them; Vue's
 * createApp records the root and the mount. The app's own utils run for real.
 */

import Module, { syncBuiltinESMExports } from 'node:module'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// main.js reads its manifest fragments through webpack's build-time
// `require.context`. Under Vitest every module gets its own `require` from
// node's createRequire, so the stand-in is attached there.
const originalCreateRequire = Module.createRequire
Module.createRequire = (...args) => {
	const req = originalCreateRequire(...args)
	req.context = (...contextArgs) => globalThis.__requireContext(...contextArgs)
	return req
}
syncBuiltinESMExports()

const boot = vi.hoisted(() => ({ reset() {
	Object.assign(boot, {
		calls: [],
		builds: [],
		integrations: [],
		apps: [],
		routers: [],
		states: {},
		capabilities: { theming: { name: 'Gemeente Test' } },
		registerTranslations: () => undefined,
		loadTranslations: () => undefined,
		hasFieldInspection: false,
		appManifestCalls: [],
	})
} }))

// publicPath.js assigns webpack's runtime global; give it one to assign to.
globalThis.__webpack_public_path__ = ''

vi.mock('@conduction/nextcloud-vue', async (importOriginal) => {
	const actual = await importOriginal()
	const record = (name) => (...args) => { boot.calls.push([name, ...args]) }
	// The app's components import more of the library than the shared stub
	// carries; any other export is an inert component or a no-op function.
	const inert = (target, key) => {
		if (key in target || typeof key !== 'string' || key === 'then') {
			return target[key]
		}
		return /^[A-Z]/.test(key) ? { name: key, render: () => null } : () => undefined
	}
	return new Proxy({
		...actual,
		builtinIntegrations: [],
		CnPageRenderer: { name: 'CnPageRenderer' },
		defaultPageTypes: { index: 'IndexType' },
		fieldInspectionIntegration: { id: 'field-inspection', label: 'Field inspection' },
		buildManifest: (base, fragments, layout) => {
			boot.builds.push({ base, fragments, layout })
			return { ...base, pages: [{ id: 'Dashboard', route: '/', type: 'dashboard' }], menu: [] }
		},
		getSharedRegistry: () => ({
			has: (id) => id === 'field-inspection' && boot.hasFieldInspection,
			unregister: (id) => boot.calls.push(['unregister', id]),
		}),
		registerBuiltinDashboardWidgets: record('registerBuiltinDashboardWidgets'),
		registerIcons: record('registerIcons'),
		registerIntegration: (integration) => boot.integrations.push(integration),
		registerTranslations: (...args) => {
			boot.calls.push(['registerTranslations'])
			return boot.registerTranslations(...args)
		},
		registerMyTeamSource: record('registerMyTeamSource'),
		registerMyTeamNameSource: record('registerMyTeamNameSource'),
		useAppManifest: (appId, manifest, options) => {
			boot.appManifestCalls.push([appId, manifest, options])
			return { manifest: { value: manifest } }
		},
	}, { get: inert, has: (target, key) => key !== 'then' })
})
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => (key in boot.states ? boot.states[key] : fallback),
}))
vi.mock('@nextcloud/capabilities', () => ({ getCapabilities: () => boot.capabilities }))
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (app, text) => text,
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
	loadTranslations: (...args) => boot.loadTranslations(...args),
}))
vi.mock('vue-router', () => ({
	createWebHistory: (base) => ({ base }),
	createRouter: (options) => {
		const router = { ...options, guards: [], after: [], currentRoute: { value: undefined },
			beforeEach(fn) { router.guards.push(fn) },
			afterEach(fn) { router.after.push(fn) } }
		boot.routers.push(router)
		return router
	},
}))
vi.mock('vue', async (importOriginal) => {
	const actual = await importOriginal()
	return {
		...actual,
		createApp: (root) => {
			const app = { root, plugins: [], provided: {}, mountedOn: null, config: { globalProperties: {} },
				use(plugin) { app.plugins.push(plugin); return app },
				provide(key, value) { app.provided[key] = value; return app },
				mount(el) { app.mountedOn = el; return app } }
			boot.apps.push(app)
			return app
		},
	}
})
vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
	showWarning: vi.fn(),
	showInfo: vi.fn(),
	getFilePickerBuilder: vi.fn(),
	spawnDialog: vi.fn(),
}))
vi.mock('../../src/App.vue', () => ({ default: { name: 'App' } }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

/**
 * Boot main.js fresh.
 *
 * @param {object} [page] `states`, `fragments` by file name, `pathname`.
 * @return {Promise<object>} What boot recorded.
 */
async function start({ states = {}, fragments = {}, pathname = '/apps/dossiq/' } = {}) {
	boot.states = states
	const context = (key) => fragments[key]
	context.keys = () => Object.keys(fragments)
	globalThis.__requireContext = (...args) => { boot.context = args; return context }
	window.history.replaceState({}, '', pathname)
	vi.resetModules()
	await import('../../src/main.js')
	return boot
}

describe('main.js boot', { timeout: 60000 }, () => {
	beforeEach(() => {
		boot.reset()
	})

	afterEach(() => {
		delete globalThis.__requireContext
		vi.restoreAllMocks()
	})

	it('registers icons and widgets, then the translations, and mounts on #content', async () => {
		const booted = await start()

		expect(booted.calls.map(([name]) => name).slice(0, 3)).toEqual([
			'registerIcons',
			'registerBuiltinDashboardWidgets',
			'registerTranslations',
		])
		expect(booted.apps).toHaveLength(1)
		const app = booted.apps[0]
		expect(app.mountedOn).toBe('#content')
		expect(app.plugins[1]).toBe(booted.routers[0])
		expect(app.config.globalProperties.t('dossiq', 'Cases')).toBe('Cases')
		expect(app.config.globalProperties.n('dossiq', 'one case', '{n} cases', 2)).toBe('{n} cases')
		expect(app.provided.cnAppConfig).toEqual({})
	})

	it('registers the field inspection integration with dossiq\'s offline schemas, replacing a registered one', async () => {
		boot.hasFieldInspection = true
		const booted = await start()

		expect(booted.calls).toContainEqual(['unregister', 'field-inspection'])
		expect(booted.integrations).toEqual([{
			id: 'field-inspection',
			label: 'Field inspection',
			offlineConfig: {
				plannedSchema: 'fieldInspection',
				referenceSchema: 'inspectionChecklist',
				templateRefField: 'checklistTemplateRef',
				resultSchema: 'checklistResult',
				assigneeField: 'inspectorRef',
				dateField: 'scheduledAt',
				titleField: 'caseRef',
			},
		}])
	})

	it('does not unregister a field inspection integration that is not there', async () => {
		const booted = await start()

		expect(booted.calls.find(([name]) => name === 'unregister')).toBeUndefined()
		expect(booted.integrations).toHaveLength(1)
	})

	it('reads the manifest.d fragments in file-name order', async () => {
		const booted = await start({ fragments: { './b.json': { id: 'b' }, './a.json': { id: 'a' } } })

		expect(booted.context).toEqual(['./manifest.d/', false, /\.json$/])
		expect(booted.builds[0].fragments).toEqual([{ id: 'a' }, { id: 'b' }])
	})

	it('builds from the full layout only for the full structure profile', async () => {
		const full = (await start({ states: { menu_structure: 'full' } })).builds[0].layout
		boot.reset()
		const simple = (await start({ states: { menu_structure: 'simple' } })).builds[0].layout
		boot.reset()
		const unknown = (await start({ states: { menu_structure: 'compact' } })).builds[0].layout

		expect(full).not.toEqual(simple)
		expect(unknown).toEqual(simple)
	})

	it('sets the WOO report flags from the initial state', async () => {
		const throughput = await start({ states: { woo_reports: { throughput: true } } })
		const runtime = throughput.appManifestCalls[0][1].runtime
		expect(runtime.woo).toEqual({ throughputReader: true, reportsOffered: true })
		expect(typeof runtime.user.isAdmin).toBe('boolean')

		boot.reset()
		const parties = await start({ states: { woo_reports: { parties: true } } })
		expect(parties.appManifestCalls[0][1].runtime.woo).toEqual({ throughputReader: false, reportsOffered: true })

		boot.reset()
		const none = await start()
		expect(none.appManifestCalls[0][1].runtime.woo).toEqual({ throughputReader: false, reportsOffered: false })
	})

	it('resolves the manifest as a delta over the bundled one and hands the app config on', async () => {
		const booted = await start({ states: { config: { caseLabel: 'Zaak' } } })

		expect(booted.appManifestCalls).toHaveLength(1)
		expect(booted.appManifestCalls[0][0]).toBe('dossiq')
		expect(booted.appManifestCalls[0][2]).toEqual({ mergeStrategy: 'delta' })
		expect(booted.apps[0].provided.cnAppConfig).toEqual({ caseLabel: 'Zaak' })
	})

	it('routes every manifest page under a base taken from the URL, with the permission guard', async () => {
		const booted = await start({ pathname: '/index.php/apps/dossiq/cases' })
		const router = booted.routers[0]

		expect(router.history.base).toBe('/index.php/apps/dossiq')
		expect(router.routes.some((route) => route.name === 'Dashboard' && route.path === '/')).toBe(true)
		expect(router.guards).toHaveLength(1)
		expect(router.after.length).toBeGreaterThanOrEqual(1)
	})

	it('hands the library the my-team loaders', async () => {
		const booted = await start()
		const names = booted.calls.map(([name]) => name)

		expect(names).toContain('registerMyTeamSource')
		expect(names).toContain('registerMyTeamNameSource')
		expect(typeof booted.calls.find(([name]) => name === 'registerMyTeamSource')[1]).toBe('function')
	})

	it('still mounts when registering the library translations throws', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		vi.spyOn(console, 'error').mockImplementation(() => {})
		boot.registerTranslations = () => { throw new Error('no l10n') }
		const booted = await start()

		expect(booted.apps[0].mountedOn).toBe('#content')
		expect(booted.calls.map(([name]) => name)).toContain('registerTranslations')
		expect(booted.calls.map(([name]) => name)).toContain('registerBuiltinDashboardWidgets')
	})

	it('still mounts when loading the app translations throws or rejects', async () => {
		boot.loadTranslations = () => { throw new Error('404') }
		expect((await start()).apps[0].mountedOn).toBe('#content')

		boot.reset()
		boot.loadTranslations = () => Promise.reject(new Error('404'))
		const booted = await start()
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(booted.apps[0].mountedOn).toBe('#content')
	})

	it('renders App with the resolved manifest, the registry and copies of the page types', async () => {
		const booted = await start()
		const root = booted.apps[0].root
		// Vue unwraps the setup() ref on the instance; do the same by hand.
		const vnode = root.render.call({ resolvedManifest: root.setup().resolvedManifest.value })

		expect(vnode.type).toEqual({ name: 'App' })
		expect(vnode.props.pageTypes).toEqual({ index: 'IndexType' })
		expect(vnode.props.manifest.pages[0].id).toBe('Dashboard')
		expect(typeof vnode.props.registry).toBe('object')
	})
})
