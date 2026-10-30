// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A custom page declares both halves of `config.register` / `config.schema`,
 * or neither.
 *
 * CnPageRenderer auto-registers an object type for a custom page only when
 * both are set; with one of the two it registers nothing and warns on every
 * visit ("declares only one of config.register / config.schema"). The
 * Workflow board carried `register: dossiq` alone, so every board visit logged
 * that warning while the board loaded its three schemas through
 * initializeStores() anyway.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'
import menuLayoutFull from '../../src/menu-layout.json'
import menuLayoutSimple from '../../src/menu-layout.simple.json'

const filled = (value) => typeof value === 'string' && value.length > 0

/**
 * The custom pages whose config holds one half of the pair.
 *
 * @param {Array<object>} pages Pages (or page overlays) with `config`.
 * @return {Array<string>} Their ids.
 */
function halfDeclared(pages) {
	return (pages || [])
		.filter((page) => {
			const config = page?.config || {}
			return filled(config.register) !== filled(config.schema)
		})
		.map((page) => page.id)
}

describe('custom page register/schema pairs', () => {
	it('no custom page in the manifest declares only one of the pair', () => {
		const custom = manifest.pages.filter((page) => page.type === 'custom')
		expect(halfDeclared(custom)).toEqual([])
	})

	it('no structure profile adds one half to a custom page', () => {
		const customIds = new Set(
			manifest.pages
				.filter((page) => page.type === 'custom')
				.map((page) => page.id),
		)
		for (const layout of [menuLayoutFull, menuLayoutSimple]) {
			const overlays = (layout.pages || []).filter((page) =>
				customIds.has(page.id),
			)
			expect(halfDeclared(overlays)).toEqual([])
		}
	})
})
