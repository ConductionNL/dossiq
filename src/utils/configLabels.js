/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A manifest label written as `config:<key>|<fallback>` takes the app
 * config's value for that key (the portal channel named after the portal,
 * "Mijn Zuiddrecht"), and the fallback when the setting is empty. Only that
 * exact shape is read. It does not start with `@`, so the manifest schema does
 * not read it as one of the library's tokens (`@config.city` and the like are
 * left to the pages that resolve them).
 *
 * @spec openspec/specs/portal-channel-label/spec.md
 */

const LABEL = /^config:([A-Za-z0-9_]+)\|(.*)$/s

/**
 * The value of one label: the config value, or the fallback.
 *
 * @param {string} text The label as written.
 * @param {object} config The app config.
 * @return {string} The label.
 */
function labelOf(text, config) {
	const match = LABEL.exec(text)
	if (match === null) {
		return text
	}
	const value = String(config[match[1]] ?? '').trim()
	return value === '' ? match[2] : value
}

/**
 * Walk a value and replace every `config:<key>|<fallback>` string in place.
 *
 * @param {unknown} node The value.
 * @param {object} config The app config.
 * @return {unknown} The value.
 */
function walk(node, config) {
	if (Array.isArray(node)) {
		node.forEach((item, index) => {
			node[index] = typeof item === 'string' ? labelOf(item, config) : walk(item, config)
		})
		return node
	}
	if (node && typeof node === 'object') {
		for (const [key, value] of Object.entries(node)) {
			node[key] = typeof value === 'string' ? labelOf(value, config) : walk(value, config)
		}
	}
	return node
}

/**
 * The manifest with every `config:<key>|<fallback>` label filled from the app config.
 *
 * @param {object} manifest The manifest; changed in place.
 * @param {object} config The app config (`loadState('dossiq', 'config')`).
 * @return {object} The manifest.
 * @spec openspec/specs/portal-channel-label/spec.md#requirement-the-portal-channel-is-named-after-the-portal
 */
export function withConfigLabels(manifest, config) {
	return walk(manifest, config && typeof config === 'object' ? config : {})
}
