const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')

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

const appId = 'procest'
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

// Use local source when available (monorepo dev), otherwise fall back to npm
// package. The USE_LOCAL_LIB env var lets CI/release builds force the published
// @conduction/nextcloud-vue (npm) even when a stale sibling worktree is present
// next to this repo: `USE_LOCAL_LIB=false` disables the sibling-source alias.
// LOCAL_LIB_PATH repoints the alias at another checkout of the library's `src`
// (e.g. a git worktree on a feature branch), so a library change can be built
// and tested here without touching the shared sibling checkout.
const localLib = process.env.LOCAL_LIB_PATH
	? path.resolve(process.env.LOCAL_LIB_PATH)
	: path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = process.env.USE_LOCAL_LIB === 'false'
	? false
	: fs.existsSync(localLib)

webpackConfig.resolve = {
	extensions: ['.vue', '.js'],
	// @nextcloud/dialogs v6's FilePicker chunk imports node's 'path' module
	// (webpack 5 no longer auto-polyfills node core modules). The FilePicker
	// UI is not used by this app; stub it out rather than shipping a real
	// polyfill so the browser bundle stays free of node internals.
	fallback: {
		path: false,
	},
	alias: {
		'@': path.resolve(__dirname, 'src'),
		...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		// VUE 3 STAGING (ADR-066): route the runtime `vue` import to @vue/compat
		// (MODE 2) so the un-migrated Vue-2 template syntax stays correct during
		// the straddle. vue-loader still finds the real compiler via vue/compiler-sfc.
		// PURE VUE 3 (ADR-066 task 6.1 — @vue/compat removed): point at the real
		// Vue 3 runtime, one ABSOLUTE file so procest + the aliased lib source share
		// one copy (dual-copy = two currentRenderingInstance states → CnAppRoot null
		// crash). The lib + procest source are now compat-construct-free, so no
		// @vue/compat runtime/compiler is needed.
		'vue$': path.resolve(__dirname, 'node_modules/vue/dist/vue.runtime.esm-bundler.js'),
		'pinia$': path.resolve(__dirname, 'node_modules/pinia'),
		// Dedupe vue-router to ONE copy (absolute file): the aliased lib worktree
		// ships its own vue-router (a different MAJOR), so a per-importer resolve
		// gives @nextcloud/vue's RouterLink a different router instance than
		// app.use(router) provided → NcAppNavigationItem's <router-link> scoped
		// slot gets undefined props (href destructure crash). One copy = one router.
		'vue-router$': path.resolve(__dirname, 'node_modules/vue-router/dist/vue-router.mjs'),
		// v9 is ESM-only: exports maps '.' -> ./dist/index.mjs with no main/module,
		// so a directory alias can't resolve it. Point at the explicit entry file
		// (also dedupes the aliased lib worktree's own v9 copy onto this one).
		'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue/dist/index.mjs'),
		// @nextcloud/dialogs v6 ships its stylesheet at dist/style.css and exposes it
		// via the package "exports" map. When the aliased nextcloud-vue source imports
		// '@nextcloud/dialogs/style.css', this webpack build resolves the raw subpath
		// (not the exports condition), so point it at the real file explicitly.
		'@nextcloud/dialogs/style.css$': path.resolve(__dirname, 'node_modules/@nextcloud/dialogs/dist/style.css'),
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
			// SCSS used by aliased @conduction/nextcloud-vue components (e.g. CnCard, CnDataTable)
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
	new webpack.DefinePlugin({ appVersion: JSON.stringify(process.env.npm_package_version) }),
]

// Force @nextcloud/dialogs to resolve from this app's node_modules,
// preventing the nextcloud-vue submodule's nested deps (Vue 3) from leaking in.
webpackConfig.resolve.alias['@nextcloud/dialogs'] = path.resolve(__dirname, 'node_modules/@nextcloud/dialogs')

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
				// Matches both node_modules entries AND the monorepo-dev alias
				// `../nextcloud-vue/src/...` which webpack resolves outside
				// node_modules when @conduction/nextcloud-vue is aliased to it.
				test: /[\\/]node_modules[\\/](@nextcloud[\\/]vue|@conduction[\\/]nextcloud-vue)[\\/]|[\\/]nextcloud-vue[\\/]src[\\/]/,
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
