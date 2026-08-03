const {
	defineConfig,
} = require('@eslint/config-helpers')

const js = require('@eslint/js')

const {
	FlatCompat,
} = require('@eslint/eslintrc')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([{
	extends: compat.extends('@nextcloud'),

	settings: {
		'import/resolver': {
			alias: {
				map: [
					['@', './src'],
					['@conduction/nextcloud-vue', '../nextcloud-vue/src'],
				],
				extensions: ['.js', '.ts', '.vue', '.json', '.css'],
			},
		},
	},

	rules: {
		'jsdoc/check-tag-names': ['warn', { definedTags: ['spec'] }],
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
		// Vue 3 (ADR-066): the shared @nextcloud eslint preset is still
		// Vue-2-oriented and enables `vue/no-v-model-argument`, which forbids
		// `v-model:<arg>`. Under Vue 3 an argument is the ONLY way to bind a
		// non-default model — `@nextcloud/vue` v9's NcDialog declares its model
		// as `defineModel('open')` (emits `update:open`), so `v-model:open` is
		// the required syntax, not a violation. The Vue-2 rule is therefore
		// incorrect for this app and is disabled. Mirrors decidesk's
		// `vue/no-v-for-template-key` exemption.
		'vue/no-v-model-argument': 'off',
		'vue/enforce-style-attribute': ['error', { allow: ['scoped'] }],
		'@typescript-eslint/no-explicit-any': 'off',
		'n/no-missing-import': 'off',
		'import/named': 'off', // disable named import checking — alias resolver can't parse transitive Vue SFC exports
		'import/namespace': 'off',
		'import/default': 'off',
		'import/no-named-as-default': 'off',
		'import/no-named-as-default-member': 'off',
		'import/no-unresolved': ['error', { ignore: ['^@conduction/nextcloud-vue'] }],
	},
}])
