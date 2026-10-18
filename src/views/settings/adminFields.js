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
 * Huisstijl: the logo is Nextcloud theming's, the theme is thematiq's brand
 * for dossiq, and only the name staff know the app by is dossiq's own setting.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The fields.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function houseStyleFields(t) {
	return [
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
