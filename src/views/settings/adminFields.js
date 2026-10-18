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
