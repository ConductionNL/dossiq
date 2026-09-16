/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The first run reports the minimum, and requires none of it.
 *
 * The failure this guards is quiet in a browser: a readiness item promoted into
 * `setup.steps` becomes a prompt CnSetupWizard cannot fulfil, and a step marked
 * `required` blocks the whole app behind something an administrator is allowed
 * to leave open. Both look like a working wizard until somebody installs the
 * app on a fresh instance and cannot get past it.
 *
 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const manifest = readJson('src', 'manifest.json')
const readiness = readJson('lib', 'Settings', 'first_run_readiness.json')
const adminRoot = read('src', 'views', 'settings', 'AdminRoot.vue')
const tab = read('src', 'views', 'settings', 'tabs', 'FirstRunTab.vue')
const controller = read('lib', 'Controller', 'SetupController.php')

describe('the readiness declaration', () => {
	it('names the five items the change asks for', () => {
		expect(readiness.items.map((item) => item.id)).toEqual([
			'organisation',
			'mail-account',
			'published-case-type',
			'role-with-holder',
			'working-calendar',
		])
	})

	it('gives every item a title and the screen that satisfies it', () => {
		for (const item of readiness.items) {
			expect(item.title, `${item.id} has no title`).toBeTruthy()
			expect(item.screen, `${item.id} leads nowhere`).toBeTruthy()
			expect(item.body, `${item.id} says nothing about itself`).toBeTruthy()
		}
	})

	it('lives outside the manifest, because the v2 schema forbids the key', () => {
		// additionalProperties:false holds at the top level and on `setup`, in
		// all three copies that matter. A readiness block in the manifest would
		// fail validate-manifest.js the day it shipped.
		expect(manifest.readiness).toBeUndefined()
		expect(manifest.setup.readiness).toBeUndefined()

		const schema = readJson('tests', 'schemas', 'app-manifest-v2.schema.json')
		expect(schema.additionalProperties).toBe(false)
		expect(schema.properties.setup.additionalProperties).toBe(false)
		expect(Object.keys(schema.properties)).not.toContain('readiness')
	})
})

describe('nothing in the readiness list gates the app', () => {
	it('leaves register-check as the only required step', () => {
		const required = manifest.setup.steps.filter((step) => step.required === true)
		expect(required.map((step) => step.id)).toEqual(['register-check'])
	})

	it('promotes no readiness item into the wizard steps', () => {
		const stepIds = manifest.setup.steps.map((step) => step.id)
		for (const item of readiness.items) {
			expect(stepIds, `${item.id} is both a readiness item and a wizard step`).not.toContain(item.id)
		}
	})

	it('reports readiness beside the steps rather than inside them', () => {
		expect(controller).toContain("$response['readiness']")
		expect(controller).toContain("$response['tourSteps']")
		// Not folded into `steps`: an unreported step is UNKNOWN to CnAppRoot
		// and a reported one it cannot prompt for is worse.
		expect(controller).not.toContain("'steps' => $this->readiness")
	})
})

describe('the screen an administrator reads it on', () => {
	it('is a section of admin settings, mounted first', () => {
		expect(adminRoot).toContain('id="section-first-run"')
		expect(adminRoot).toContain('<FirstRunTab')
		expect(adminRoot.indexOf('section-first-run')).toBeLessThan(adminRoot.indexOf('Case Type Management'))
	})

	it('reads the status live rather than a stored flag', () => {
		expect(tab).toContain("/apps/dossiq/api/setup/status")
		expect(tab).toContain('async reload()')
		expect(tab).not.toContain('localStorage')
	})

	it('shows the failure sentence when a read could not be made', () => {
		expect(tab).toContain('item.failure')
		expect(tab).toContain('This could not be read: {reason}')
	})

	it('links each item to the screen it names', () => {
		expect(tab).toContain(':href="item.screen"')
	})
})
