// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * The field lists of the admin page's declarative settings sections
 * (CnSettingsFieldsSection), kept apart from AdminRoot so they can be read and
 * tested without mounting the page.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

/**
 * Huisstijl: the organisation's name and logo are Nextcloud theming's, the
 * theme is thematiq's brand for dossiq, and only the name staff know the app by
 * is dossiq's own setting. Both theming writes are admin only on the server.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The fields.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function houseStyleFields(t) {
	return [
		{
			// Nextcloud theming's own name, read from the capabilities and
			// written through theming's admin-only updateStylesheet.
			key: 'organisationName',
			label: t('dossiq', 'Name of the organisation'),
			url: '/ocs/v2.php/cloud/capabilities?format=json',
			valuePath: 'ocs.data.capabilities.theming.name',
			saveUrl: '/apps/theming/ajax/updateStylesheet',
			saveKey: 'value',
			saveParams: { setting: 'name' },
		},
		{
			key: 'staff_app_name',
			label: t('dossiq', 'Name of the app for staff'),
			placeholder: 'Dossiq',
		},
		{
			key: 'theme',
			label: t('dossiq', 'Theme'),
			type: 'select',
			url: '/apps/thematiq/settings/app-brands',
			valuePath: 'brands.dossiq.tokenSet',
			saveUrl: '/apps/thematiq/settings/app-brands/dossiq',
			saveKey: 'tokenSet',
			optionsUrl: '/apps/thematiq/settings/app-brands',
			optionsPath: 'tokenSets',
			optionLabel: 'name',
			optionValue: 'id',
		},
		{
			key: 'logo',
			label: t('dossiq', 'Other logo'),
			type: 'image',
			url: '/apps/thematiq/settings/theming',
			valuePath: 'logo_url',
			initialsFrom: 'organisationName',
			uploadUrl: '/apps/theming/ajax/uploadImage',
			uploadParams: { key: 'logo' },
			uploadValuePath: 'data.url',
		},
	]
}

/**
 * Teksten en toegang: the sender of dossiq's messages and the text above Mijn
 * zaken in the portal; which websites may call the portal is configured where
 * the portal runs, so it is a pointer here.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The fields.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function textsAndAccessFields(t) {
	return [
		{
			key: 'email_from_name',
			label: t('dossiq', 'Sender of messages'),
		},
		{
			key: 'portal_intro_text',
			label: t('dossiq', 'Text above My cases in the portal'),
			type: 'textarea',
			rows: 2,
		},
		{
			key: 'allowedWebsites',
			label: t('dossiq', 'Allowed websites (CORS)'),
			readOnly: true,
			default: t(
				'dossiq',
				'Set in the portal app (portaliq) and in Nextcloud, not here.',
			),
		},
	]
}

/**
 * The Kadaster (BRK) and WOZ bindings IntegrationMode reads: the source, the
 * service address and the key, which is written and never read back.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The fields.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function kadasterFields(t) {
	const modes = [
		{ value: 'log', label: t('dossiq', 'None: no requests go outside') },
		{ value: 'test', label: t('dossiq', 'Test register') },
		{ value: 'live', label: t('dossiq', 'Live') },
	]
	const keyHint = t('dossiq', 'Replace only; once saved it is never shown again.')
	return ['brk', 'woz'].flatMap((integration) => {
		const name =
			integration === 'brk'
				? t('dossiq', 'Kadaster (BRK)')
				: t('dossiq', 'WOZ')
		return [
			{
				key: `integration.${integration}.mode`,
				label: t('dossiq', '{name}: source', { name }),
				type: 'select',
				options: modes,
				default: 'log',
			},
			{
				key: `integration.${integration}.baseUrl`,
				label: t('dossiq', '{name}: service address', { name }),
				placeholder: 'https://',
			},
			{
				key: `integration.${integration}.apiKey`,
				label: t('dossiq', '{name}: API key', { name }),
				type: 'secret',
				hint: keyHint,
			},
		]
	})
}
