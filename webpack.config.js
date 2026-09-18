const webpackConfig = require('@nextcloud/webpack-vue-config')
const path = require('path')
const { VueLoaderPlugin } = require('vue-loader')
const webpack = require('webpack')
const { readAppVersion } = require('./scripts/appVersion.js')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
// Production builds must not ship a full 'source-map' devtool: it emits a
// separate .js.map exposing original, unminified source alongside the
// publicly-served bundle. Use the non-source-exposing variant instead.
// @spec openspec/changes/performance-hardening-audit-log-and-boot/specs/performance-hardening/spec.md
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'nosources-source-map'

webpackConfig.stats = {
	colors: true,
	modules: false,
}

const appId = 'dossiq'
webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'main.js'),
		filename: appId + '-main.js',
	},
	adminSettings: {
		import: path.join(__dirname, 'src', 'settings.js'),
		filename: appId + '-settings.js',
	},
	emailSettings: {
		import: path.join(__dirname, 'src', 'emailSettings.js'),
		filename: appId + '-email-settings.js',
	},
	personalSettings: {
		import: path.join(__dirname, 'src', 'personalSettings.js'),
		filename: appId + '-personal-settings.js',
	},
	casesOverviewWidget: {
		import: path.join(__dirname, 'src', 'casesOverviewWidget.js'),
		filename: appId + '-casesOverviewWidget.js',
	},
	overdueCasesWidget: {
		import: path.join(__dirname, 'src', 'overdueCasesWidget.js'),
		filename: appId + '-overdueCasesWidget.js',
	},
	myTasksWidget: {
		import: path.join(__dirname, 'src', 'myTasksWidget.js'),
		filename: appId + '-myTasksWidget.js',
	},
	deadlineAlertsWidget: {
		import: path.join(__dirname, 'src', 'deadlineAlertsWidget.js'),
		filename: appId + '-deadlineAlertsWidget.js',
	},
	taskRemindersWidget: {
		import: path.join(__dirname, 'src', 'taskRemindersWidget.js'),
		filename: appId + '-taskRemindersWidget.js',
	},
	stalledCasesWidget: {
		import: path.join(__dirname, 'src', 'stalledCasesWidget.js'),
		filename: appId + '-stalledCasesWidget.js',
	},
	startCaseWidget: {
		import: path.join(__dirname, 'src', 'startCaseWidget.js'),
		filename: appId + '-startCaseWidget.js',
	},
}

// @conduction/nextcloud-vue resolves normally from node_modules. To build
// against a local checkout instead, `npm i ../nextcloud-vue/` (npm symlinks
// it in) and run its own build there.
webpackConfig.resolve = {
	extensions: ['.vue', '.js'],
	// Resolve the symlinked local checkout from its place INSIDE this app's
	// node_modules, not its real path. Needed because the library's dist
	// VENDORS @nextcloud/dialogs and imports it by relative path, which the
	// `@nextcloud/dialogs$` alias below cannot intercept — so that copy's bare
	// `@nextcloud/files` request resolves in the lib's own tree, and the
	// `buffer` polyfill it ends up needing is then searched for by walking up
	// from `../nextcloud-vue/`, escaping to `/` past the copy this app has
	// installed. Keeping the symlinked path gives npm-link semantics: the lib's
	// deps win, ours are the fallback. No effect when nothing is symlinked.
	symlinks: false,
	// @nextcloud/dialogs v6's FilePicker chunk imports node's 'path' module
	// (webpack 5 no longer auto-polyfills node core modules). The FilePicker
	// UI is not used by this app; stub it out rather than shipping a real
	// polyfill so the browser bundle stays free of node internals.
	fallback: {
		path: false,
	},
	alias: {
		'@': path.resolve(__dirname, 'src'),
		// Deduplicate shared packages so a symlinked local nextcloud-vue
		// checkout uses the same instances as the app (prevents dual-Pinia /
		// dual-Vue bugs).
		// VUE 3 STAGING (ADR-066): route the runtime `vue` import to @vue/compat
		// (MODE 2) so the un-migrated Vue-2 template syntax stays correct during
		// the straddle. vue-loader still finds the real compiler via vue/compiler-sfc.
		// PURE VUE 3 (ADR-066 task 6.1 — @vue/compat removed): point at the real
		// Vue 3 runtime, one ABSOLUTE file so dossiq + a symlinked lib share one
		// copy (dual-copy = two currentRenderingInstance states → CnAppRoot null
		// crash).
		vue$: path.resolve(
			__dirname,
			'node_modules/vue/dist/vue.runtime.esm-bundler.js',
		),
		pinia$: path.resolve(__dirname, 'node_modules/pinia'),
		// Dedupe vue-router to ONE copy (absolute file): a symlinked lib checkout
		// ships its own vue-router (a different MAJOR), so a per-importer resolve
		// gives @nextcloud/vue's RouterLink a different router instance than
		// app.use(router) provided → NcAppNavigationItem's <router-link> scoped
		// slot gets undefined props (href destructure crash). One copy = one router.
		'vue-router$': path.resolve(
			__dirname,
			'node_modules/vue-router/dist/vue-router.mjs',
		),
		// v9 is ESM-only: exports maps '.' -> ./dist/index.mjs with no main/module,
		// so a directory alias can't resolve it. Point at the explicit entry file
		// (also dedupes a symlinked lib checkout's own v9 copy onto this one).
		'@nextcloud/vue$': path.resolve(
			__dirname,
			'node_modules/@nextcloud/vue/dist/index.mjs',
		),
		// @nextcloud/dialogs v6 ships its stylesheet at dist/style.css and exposes it
		// via the package "exports" map. When nextcloud-vue imports
		// '@nextcloud/dialogs/style.css', this webpack build resolves the raw subpath
		// (not the exports condition), so point it at the real file explicitly.
		'@nextcloud/dialogs/style.css$': path.resolve(
			__dirname,
			'node_modules/@nextcloud/dialogs/dist/style.css',
		),
	},
}

webpackConfig.module = {
	rules: [
		{
			test: /\.vue$/,
			loader: 'vue-loader',
		},
		{
			test: /\.css$/,
			use: ['style-loader', 'css-loader'],
		},
		{
			// SCSS used by @conduction/nextcloud-vue components (e.g. CnCard, CnDataTable)
			test: /\.scss$/,
			use: ['style-loader', 'css-loader', 'sass-loader'],
		},
		{
			// Leaflet marker icons and other image assets
			test: /\.(png|jpe?g|gif|svg)$/,
			type: 'asset/resource',
			generator: {
				filename: 'img/[name][ext]',
			},
		},
	],
}

webpackConfig.plugins = [
	new VueLoaderPlugin(),
	new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
	// The version Nextcloud installs, from appinfo/info.xml. package.json's
	// version is 0.1.0 and never bumped, and @nextcloud/vue prints this global
	// in the settings dialog footer, which read "dossiq 0.1.0" on a 0.3.x install.
	new webpack.DefinePlugin({
		appVersion: JSON.stringify(readAppVersion()),
	}),
]

// Force @nextcloud/dialogs to resolve from this app's node_modules,
// preventing the nextcloud-vue submodule's nested deps from leaking in.
//
// v7 IS ESM-ONLY: its exports map declares only '.' -> ./dist/index.mjs with no
// `main`/`module` fallback, so a DIRECTORY alias no longer resolves (webpack
// applies an exports map to a PACKAGE REQUEST, never to an absolutised path —
// the aliased directory has nothing to resolve against). Use an exact-match `$`
// alias onto the explicit entry FILE, exactly as `@nextcloud/vue$` does.
webpackConfig.resolve.alias['@nextcloud/dialogs$'] = path.resolve(
	__dirname,
	'node_modules/@nextcloud/dialogs/dist/index.mjs',
)

// @nextcloud/axios is pinned to ~2.5.2 (via package.json overrides) which still
// declares both `import` and `require` exports conditions, so the package can
// be required from @nextcloud/vue's CJS bundle without webpack 5 tripping on
// the exports field. No alias needed; the pin alone is sufficient. Mirrors
// decidesk's working webpack config.

// Share Vue + @nextcloud/vue + pinia + icons + @conduction/nextcloud-vue
// across every entry-point so each widget bundle no longer inlines its own
// ~5 MB framework copy. Stable filenames (no contenthash in the JS name)
// mean each widget's `Util::addScript` PHP call can reference the chunk
// directly without a manifest. The vendor chunk is loaded once and cached
// across every widget/page in the app.
webpackConfig.optimization = {
	...(webpackConfig.optimization || {}),
	splitChunks: {
		...(webpackConfig.optimization?.splitChunks || {}),
		chunks: 'all',
		cacheGroups: {
			default: false,
			defaultVendors: false,
			ncVue: {
				name: appId + '-shared-nc-vue',
				// A symlinked local nextcloud-vue checkout resolves outside
				// node_modules (webpack follows the symlink to its real path),
				// so match on the `nextcloud-vue` path segment, not node_modules.
				test: /[\\/]node_modules[\\/]@nextcloud[\\/]vue[\\/]|[\\/]nextcloud-vue[\\/]/,
				priority: 30,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-nc-vue.js',
			},
			vendor: {
				name: appId + '-shared-vendor',
				test: /[\\/]node_modules[\\/](vue|pinia|vue-material-design-icons|@vueuse|core-js)[\\/]/,
				priority: 20,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-vendor.js',
			},
		},
	},
}

module.exports = webpackConfig
