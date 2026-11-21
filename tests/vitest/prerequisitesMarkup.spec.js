// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Prerequisites block renders the same markup, table for table and row
 * for row, whichever way its template is put together.
 *
 * Scope attributes (`data-v-…`) are build output, not markup a reader or a
 * test id sees, so they are stripped before comparing.
 *
 * @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/initial-state', () => ({
	loadState: () => ({
		php: { required: '8.3', running: '8.3.6', present: true },
		nextcloud: { min: '32', max: '34', running: '33.0.1', present: true },
		extensions: [
			{ name: 'json', why: 'Reads every payload.', present: true },
			{ name: 'zip', why: 'Packs an export.', present: false },
		],
		apps: {
			required: [
				{ id: 'openregister', name: 'Open Register', unlocks: 'Stores every case.', present: false },
			],
			optional: [
				{ id: 'humaniq', unlocks: 'Books the hours.', present: false },
				{ id: 'hermiq', name: 'Hermiq', unlocks: 'Answers the AI steps.', present: true },
			],
		},
	}),
}))

vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({
	default: {
		name: 'NcNoteCard',
		props: { type: { type: String, default: '' } },
		render() {
			return h(
				'div',
				{ class: 'nc-note-card', 'data-type': this.type },
				this.$slots.default ? this.$slots.default() : [],
			)
		},
	},
}))

const PrerequisitesTab = (
	await import('../../src/views/settings/tabs/PrerequisitesTab.vue')
).default

describe('The Prerequisites block markup', () => {
	it('renders every table, header and row as pinned', () => {
		const html = mount(PrerequisitesTab).html().replace(/ data-v-[a-z0-9]+=""/g, '')

		expect(html).toMatchInlineSnapshot(`
			"<div class="prerequisites" data-testid="prerequisites-block">
			  <div class="nc-note-card" data-type="error" data-testid="prerequisites-blocking">Dossiq cannot run until this is installed: Open Register</div>
			  <table class="prerequisites__table" data-testid="prerequisites-platform">
			    <thead>
			      <tr>
			        <th scope="col">Platform</th>
			        <th scope="col">What it does</th>
			        <th scope="col" class="prerequisites__here">Here</th>
			      </tr>
			    </thead>
			    <tbody>
			      <tr data-testid="prerequisite-php">
			        <th scope="row">PHP 8.3 or higher</th>
			        <td>This instance runs 8.3.6</td>
			        <td><span class="prerequisites__mark cn-status-badge cn-status-badge--success">present</span></td>
			      </tr>
			      <tr data-testid="prerequisite-nextcloud">
			        <th scope="row">Nextcloud 32 to 34</th>
			        <td>This instance runs 33.0.1</td>
			        <td><span class="prerequisites__mark cn-status-badge cn-status-badge--success">present</span></td>
			      </tr>
			    </tbody>
			  </table>
			  <table class="prerequisites__table" data-testid="prerequisites-extensions">
			    <thead>
			      <tr>
			        <th scope="col">PHP extension</th>
			        <th scope="col">What it does</th>
			        <th scope="col" class="prerequisites__here">Here</th>
			      </tr>
			    </thead>
			    <tbody>
			      <tr data-testid="prerequisite-ext-json">
			        <th scope="row">json</th>
			        <td>Reads every payload.</td>
			        <td><span class="prerequisites__mark cn-status-badge cn-status-badge--success">present</span></td>
			      </tr>
			      <tr data-testid="prerequisite-ext-zip">
			        <th scope="row">zip</th>
			        <td>Packs an export.</td>
			        <td><span class="prerequisites__mark cn-status-badge cn-status-badge--default prerequisites__mark--missing">missing</span></td>
			      </tr>
			    </tbody>
			  </table>
			  <table class="prerequisites__table" data-testid="prerequisites-apps">
			    <thead>
			      <tr>
			        <th scope="col">App</th>
			        <th scope="col">What it does</th>
			        <th scope="col" class="prerequisites__here">Here</th>
			      </tr>
			    </thead>
			    <tbody>
			      <tr data-testid="prerequisite-app-openregister">
			        <th scope="row">Open Register</th>
			        <td>Stores every case.</td>
			        <td><span class="prerequisites__mark cn-status-badge cn-status-badge--default prerequisites__mark--missing">missing</span></td>
			      </tr>
			    </tbody>
			    <tbody>
			      <tr data-testid="prerequisite-app-humaniq">
			        <th scope="row">humaniq</th>
			        <td>Books the hours.</td>
			        <td><span class="prerequisites__mark cn-status-badge cn-status-badge--default prerequisites__mark--missing">missing</span></td>
			      </tr>
			      <tr data-testid="prerequisite-app-hermiq">
			        <th scope="row">Hermiq</th>
			        <td>Answers the AI steps.</td>
			        <td><span class="prerequisites__mark cn-status-badge cn-status-badge--success">present</span></td>
			      </tr>
			    </tbody>
			  </table>
			</div>"
		`)
	})
})
