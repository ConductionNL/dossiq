/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The engine sees dossiq's labels (case-configuration-i18n, REQ-CFI-01 and
 * REQ-CFI-05).
 *
 * WHY THIS SPEC EXISTS AND WHY IT IS NOT A UNIT TEST. Until 2026-09-18 the
 * dossiq register declared `x-translatable` fifty times. OpenRegister reads
 * `translatable`: `TranslationHandler::getTranslatableProperties()` tests
 * `($propertyDef['translatable'] ?? false) === true`. So for as long as the
 * prefix stood, the projection, the CSV codec and the bulk translation service
 * had never seen a single dossiq label, and nothing anywhere said so.
 *
 * A test asserting that the JSON now spells `translatable` would repeat the
 * same mistake one level up: it would prove what the file says, not what the
 * engine does. `tests/Unit/Settings/TranslatableLabelsTest.php` is that test
 * and it says so about itself. This spec is the other half. It asks a RUNNING
 * OpenRegister, over the API it serves to everybody, whether `caseType.title`
 * is in the translation projection, and it fails if it is not.
 *
 * WHAT MAKES THE ASSERTION LOAD-BEARING. `?_translationMeta=true` adds
 * `_meta.languageMeta.<property>` for EVERY TRANSLATABLE PROPERTY and for no
 * other (openregister `docs/i18n.md`, "Translation metadata envelope"). The
 * envelope is computed from the schema OpenRegister holds, after import. So
 * `languageMeta.title` present means: the register imported, the mark survived
 * the import, and the engine resolved it. Rename the key back and the envelope
 * loses the entry.
 *
 * `identifier` is the control. It is a real property of the same schema, on the
 * same object, in the same response, and it is not translatable. If it ever
 * appears in `languageMeta`, the envelope is answering about something other
 * than the mark and the passing assertion above it means nothing.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
} from './helpers/fixtures.ts'

/** The case type this spec authors, then reads back through the envelope. */
const CASE_TYPE_TITLE = `${RUN_PREFIX} Translatable type`

/** Where an object is read with the translation envelope switched on. */
const API_BASE = '/index.php/apps/openregister/api/objects'

/**
 * Read one object with the translation metadata envelope on.
 *
 * @param api    Authenticated request context.
 * @param schema The schema slug.
 * @param id     The object id.
 * @return The decoded object, envelope included.
 */
async function showWithTranslationMeta(
	api: APIRequestContext,
	schema: string,
	id: string,
): Promise<Record<string, any>> {
	const res = await api.get(
		`${API_BASE}/${REGISTER}/${schema}/${id}?_translationMeta=true`,
	)
	expect(
		res.ok(),
		`show ${schema}/${id} with _translationMeta -> ${res.status()} ${await res.text()}`,
	).toBeTruthy()
	const body = await res.json()
	return (body.object ?? body) as Record<string, any>
}

test.describe('the translation engine sees dossiq labels', () => {
	let caseTypeId = ''

	test.beforeAll(async ({ request }) => {
		const token = await getRequestToken(request)
		const created = await createObject(request, token, 'caseType', {
			title: CASE_TYPE_TITLE,
			description: `${RUN_PREFIX} a case type authored to be read back`,
		})
		caseTypeId = objectId(created)
	})

	test.afterAll(async ({ request }) => {
		const token = await getRequestToken(request)
		await cleanupRunObjects(request, token, ['caseType'])
	})

	test('caseType.title is in the translation projection', async ({ request }) => {
		const object = await showWithTranslationMeta(request, 'caseType', caseTypeId)

		const languageMeta = object._meta?.languageMeta ?? {}

		expect(
			Object.keys(languageMeta),
			'OpenRegister builds languageMeta from the properties marked `translatable` on the '
				+ 'imported schema. An empty envelope means the mark did not reach the engine, which '
				+ 'is exactly what `x-translatable` did for fifty properties until 2026-09-18.',
		).toContain('title')

		expect(
			languageMeta.title.sourceLanguage,
			'a translatable property is served with the language its value is authored in',
		).toBeTruthy()
	})

	test('caseType.description is in it too', async ({ request }) => {
		const object = await showWithTranslationMeta(request, 'caseType', caseTypeId)

		expect(Object.keys(object._meta?.languageMeta ?? {})).toContain(
			'description',
		)
	})

	test('a property nobody marked stays out of it', async ({ request }) => {
		const object = await showWithTranslationMeta(request, 'caseType', caseTypeId)

		// The control. Without it, an envelope that listed every property would
		// pass the two assertions above while proving nothing about the mark.
		expect(
			Object.keys(object._meta?.languageMeta ?? {}),
			'`identifier` is not translatable. If the envelope lists it, the envelope is not '
				+ 'answering about the `translatable` mark and neither is this spec.',
		).not.toContain('identifier')
	})
})
