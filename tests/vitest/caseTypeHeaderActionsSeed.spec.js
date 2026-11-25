// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The shipped case types name only header actions the case page declares.
 * A case type orders and promotes the page's own actions through
 * `headerActions`, `primaryAction` and `quickActions`; nextcloud-vue drops an
 * id the page does not declare, so a typo or an act that is not built yet
 * would silently leave the header unchanged. This walks every JSON file under
 * lib/Settings (packages, register seed, example sets) and checks each id.
 *
 * @spec openspec/changes/case-type-declares-its-header-actions/specs/case-type-header-actions/spec.md#requirement-the-shipped-complaint-and-grant-types-declare-the-boards-rows
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const caseDetail = buildProfiledManifest(
	buildManifest,
	readJson('src', 'manifest.json'),
	fragments,
	readJson('src', 'menu-layout.simple.json'),
).pages.find((page) => page.id === 'CaseDetail').config
const declared = new Set(caseDetail.headerActions.map((action) => action.id))

const KEYS = ['headerActions', 'primaryAction', 'quickActions']

/**
 * Every JSON file under a directory, recursively.
 *
 * @param {string} dir The directory.
 * @return {Array<string>} Absolute file paths.
 */
function jsonFiles(dir) {
	return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			return jsonFiles(full)
		}
		return entry.name.endsWith('.json') ? [full] : []
	})
}

/**
 * Every id a case type object names under KEYS. Schema definitions (an
 * object such as `{type: 'array'}`) are skipped: only a string or a list of
 * strings is a declaration.
 *
 * @param {*} node A decoded JSON value.
 * @param {string} file The file it came from.
 * @param {Array<object>} out Collected `{file, key, id}`.
 * @return {Array<object>} out
 */
function namedIds(node, file, out = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => namedIds(child, file, out))
	} else if (node && typeof node === 'object') {
		for (const key of KEYS) {
			const value = node[key]
			if (typeof value === 'string' && value !== '') {
				out.push({ file, key, id: value })
			} else if (Array.isArray(value) && value.every((entry) => typeof entry === 'string')) {
				value.forEach((id) => out.push({ file, key, id }))
			}
		}
		Object.values(node).forEach((child) => namedIds(child, file, out))
	}
	return out
}

const named = jsonFiles(path.join(ROOT, 'lib', 'Settings'))
	.flatMap((file) => namedIds(JSON.parse(fs.readFileSync(file, 'utf8')), path.relative(ROOT, file)))

describe('the shipped case types declare only ids the case page declares', () => {
	it('finds the shipped declarations', () => {
		expect(named.length).toBeGreaterThan(0)
	})

	it('names no header action the case page lacks', () => {
		const unknown = named.filter((entry) => !declared.has(entry.id))
		expect(unknown).toEqual([])
	})

	it('promotes settling the grant on the shipped grant case type (board DqSubsidie: Adopt)', () => {
		const caseType = readJson('lib', 'Settings', 'packages', 'subsidie', 'case-type.json').caseType
		expect(caseType.primaryAction).toBe('grant-settle')
		expect(declared.has('grant-settle')).toBe(true)
	})
})
