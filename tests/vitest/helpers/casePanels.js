/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Where a widget of the case page lives, now that the strip is folded.
 *
 * Every spec that checks the case page used to find its widget with
 * `config.widgets.find((w) => w.id === …)`, because every panel was a tab and
 * every tab named a top-level widget. Six tabs means ten of those panels are
 * SECTIONS inside a `case-sections` group instead, and `find` on the top-level
 * array returns `undefined` for them. That reads as "the widget was deleted",
 * which is exactly the regression these specs exist to catch, so the lookup
 * has to know about both shapes rather than each spec guessing.
 */

const fs = require('fs')
const path = require('path')

const MANIFEST_PATH = path.resolve(__dirname, '../../../src/manifest.json')

/** @return {object} The parsed manifest, read fresh so a spec can mutate on disk. */
function manifest() {
	return JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))
}

/**
 * One page's config.
 *
 * @param {string} id The manifest page id.
 * @return {object} The page's `config`.
 */
function pageConfig(id = 'CaseDetail') {
	return manifest().pages.find((entry) => entry.id === id).config
}

/**
 * Every widget the CaseDetail page renders, top-level ones and the sections
 * of every `case-sections` group, flattened.
 *
 * @return {object[]} The widget definitions.
 */
function allCaseWidgets() {
	const top = pageConfig().widgets
	const nested = top.flatMap((entry) =>
		entry.type === 'case-sections'
			? (entry.content?.sections ?? []).map((section) => section.widget)
			: [],
	)
	return [...top, ...nested]
}

/**
 * One widget of the CaseDetail page, wherever it lives.
 *
 * @param {string} id The widget id.
 * @return {object|undefined} The widget definition.
 */
function caseWidget(id) {
	return allCaseWidgets().find((entry) => entry.id === id)
}

/**
 * The tab a widget is reachable through, and the section label it carries.
 *
 * @param {string} id The widget id.
 * @return {{tab: string, label: string|null}|null} The tab label and the
 *   section label, or null when the widget is not in the strip at all.
 */
function caseTabOf(id) {
	const cfg = pageConfig()
	const strip = cfg.widgets.find((entry) => entry.id === 'case-panels')
	for (const tab of strip.content.tabs) {
		if (tab.widgetId === id) return { tab: tab.label, label: null }
		const group = cfg.widgets.find((entry) => entry.id === tab.widgetId)
		if (group?.type !== 'case-sections') continue
		const section = (group.content?.sections ?? []).find(
			(entry) => entry.widget.id === id,
		)
		if (section) return { tab: tab.label, label: section.label }
	}
	return null
}

module.exports = {
	allCaseWidgets,
	caseTabOf,
	caseWidget,
	manifest,
	pageConfig,
}
