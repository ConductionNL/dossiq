// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * No dossiq registry key reuses a widget key the library renders.
 *
 * CnWidgetGrid resolves a widget type against the app registry before the
 * library's own widgets, so an app key with a library widget's name silently
 * replaces that widget on every page of this app that asks for it.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md
 */

import { LIBRARY_WIDGET_KEYS } from '@conduction/nextcloud-vue/src/utils/libraryWidgetKeys.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const source = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const body = source.slice(source.indexOf('const registry = {'), source.indexOf('export default registry'))
const keys = [...body.matchAll(/^\t'?([A-Za-z0-9-]+)'?: \{/gm)].map((match) => match[1])

describe('the dossiq widget registry', () => {
	it('is read', () => {
		expect(keys.length).toBeGreaterThan(50)
	})

	it('does not shadow a library widget', () => {
		expect(keys.filter((key) => LIBRARY_WIDGET_KEYS.includes(key))).toEqual([])
	})

	it('renders the case Review tab with the dossiq panel under its own key', () => {
		const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
		const found = JSON.stringify(manifest).match(/"id":"case-document-review","type":"([^"]+)"/)

		expect(found && found[1]).toBe('document-relevance-review')
		expect(keys).toContain('document-relevance-review')
	})
})
