// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * The field lists of the admin page's declarative settings sections
 * (CnSettingsFieldsSection), kept apart from AdminRoot so they can be read and
 * tested without mounting the page.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { QUEUE_URGENCY_FIELDS } from '../../utils/queueUrgencySettings.js'

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
			uploadLabel: t('dossiq', 'Other logo'),
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

/**
 * The tone of each connection status on the integrations table: a working
 * connection is green, a failing one red, the rest neutral.
 *
 * @type {Readonly<Record<string, string>>}
 * @spec openspec/specs/admin-settings/spec.md
 */
export const CONNECTION_STATUS_TONES = Object.freeze({
	configured: 'success',
	error: 'error',
})

/**
 * The menu structure: the simple menu (the default) or the full one. The boot
 * code reads it at page load, so the saved note says when people see it.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The fields.
 * @spec openspec/specs/nav-dedup-and-grouping/spec.md#REQ-PNDG-007
 */
export function menuStructureFields(t) {
	return [
		{
			key: 'menu_structure',
			label: t('dossiq', 'Menu structure'),
			hideLabel: true,
			type: 'radio',
			default: 'simple',
			options: [
				{
					value: 'simple',
					label: t('dossiq', 'Simple'),
					description: t(
						'dossiq',
						'Nine menu entries for daily work. Everything else is one step further, in settings or on a page.',
					),
				},
				{
					value: 'full',
					label: t('dossiq', 'Full'),
					description: t('dossiq', 'Every entry in the menu, as it was before.'),
				},
			],
			hint: t('dossiq', 'No page is removed. Both menus open the same pages.'),
		},
	]
}

/**
 * The queue urgency: when a case counts as critical or almost due, and what
 * priority and lying still weigh. The bounds are the server's
 * (UrgencyProfile.php), which clamps whatever still gets through.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The fields.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function queueUrgencyFields(t) {
	const text = {
		criticalDays: [
			t('dossiq', 'Critical from, working days left'),
			t('dossiq', 'From 0 to 60. Default 3.'),
		],
		warningDays: [
			t('dossiq', 'Almost due from, working days left'),
			t('dossiq', 'From 0 to 120. Default 7.'),
		],
		priorityWeight: [
			t('dossiq', 'Weight of the priority'),
			t('dossiq', 'Points per step from low to urgent, 0 to 50. Default 10.'),
		],
		idleWeight: [
			t('dossiq', 'Weight of the time lying still'),
			t('dossiq', 'Points per day without activity, 0 to 1.5. Default 0.5.'),
		],
	}
	return QUEUE_URGENCY_FIELDS.map((spec) => ({
		key: spec.key,
		label: text[spec.field][0],
		hint: text[spec.field][1],
		type: 'number',
		integer: spec.whole,
		min: 0,
		max: spec.max,
		default: spec.default,
	}))
}

/**
 * The mandate matrix behaviour: the Decidiq connection, the default term of a
 * stand-in assignment and whether an approved import goes live by itself.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The fields.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function mandateSettingsFields(t) {
	return [
		{
			key: 'mandate-delivery',
			type: 'status',
			text: t(
				'dossiq',
				'The mandate matrix (Awb art. 10:3) is being delivered in the mandaat-matrix chain. This panel will host role hierarchy, Decidiq imports and waarnemer assignments.',
			),
		},
		{
			key: 'mandaat_decidesk_connection',
			label: t('dossiq', 'Decidiq connection (Integriq)'),
			placeholder: 'decidesk-default',
			default: 'decidesk-default',
			hint: t(
				'dossiq',
				'Identifier of the Integriq connection used to fetch mandateringsbesluiten from Decidiq.',
			),
		},
		{
			key: 'mandaat_default_extension_days',
			label: t('dossiq', 'Default extension days for waarnemer assignments'),
			type: 'number',
			integer: true,
			min: 0,
			default: 14,
			hint: t(
				'dossiq',
				'Used as a hint when a waarnemer assignment is created without an explicit end date.',
			),
		},
		{
			key: 'mandaat_auto_finalize_approved',
			label: t('dossiq', 'Automatically activate a mandate import after approval'),
			type: 'switch',
			trueValue: '1',
			falseValue: '0',
		},
	]
}

/**
 * The consultation behaviour: the default term, the warning offset, the base
 * of the external response links and the bottleneck threshold.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The fields.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function consultationFields(t) {
	return [
		{
			key: 'consultation-delivery',
			type: 'status',
			text: t(
				'dossiq',
				'Structured consultation (adviesaanvraag) is being delivered in consultation-management. This panel will host advisory body registry, mandatory-gate config and n8n webhook endpoints.',
			),
		},
		{
			key: 'consultation_default_deadline_days',
			label: t('dossiq', 'Default deadline (days) for new consultations'),
			type: 'number',
			integer: true,
			min: 0,
			default: 28,
			hint: t(
				'dossiq',
				'Used when an advisory body has no explicit defaultDeadlineDays configured.',
			),
		},
		{
			key: 'consultation_warning_offset_days',
			label: t('dossiq', 'Warning offset (days before deadline)'),
			type: 'number',
			integer: true,
			min: 0,
			default: 5,
			hint: t(
				'dossiq',
				'The deadline-monitor n8n workflow uses this offset to send T-X warnings.',
			),
		},
		{
			key: 'consultation_external_response_url',
			label: t('dossiq', 'External response base URL'),
			placeholder: 'https://dossiq.example.org/consultation/respond/',
			hint: t(
				'dossiq',
				'Base URL used in secure response links sent to external advisory bodies. Must be HTTPS.',
			),
		},
		{
			key: 'consultation_bottleneck_threshold',
			label: t('dossiq', 'Bottleneck overdue-rate threshold (0-1)'),
			type: 'number',
			min: 0,
			max: 1,
			default: 0.2,
			hint: t(
				'dossiq',
				'When an advisory body exceeds this overdue-rate over the trailing 30 days, the bottleneck workflow notifies coordinators.',
			),
		},
	]
}

/**
 * The review reports: who may read the throughput per reviewer, and the two
 * switches. The server refuses the throughput switch without an existing
 * reader group, and the section shows that sentence.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The fields.
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
 */
export function reviewReportFields(t) {
	return [
		{
			key: 'wooReviewerThroughputReaders',
			label: t('dossiq', 'Reader group for the throughput report'),
			hint: t(
				'dossiq',
				'The id of an existing Nextcloud group. Administrators outside it cannot read the report either.',
			),
		},
		{
			key: 'wooReviewerThroughputReport',
			label: t('dossiq', 'Report throughput per reviewer per day'),
			type: 'switch',
			trueValue: 'true',
			falseValue: 'false',
		},
		{
			key: 'wooCollectionPartiesReport',
			label: t('dossiq', 'Report collected mail by sender, recipient and domain'),
			type: 'switch',
			trueValue: 'true',
			falseValue: 'false',
		},
	]
}

/**
 * The payment callback: the shared secret the public callback checks the
 * signature with. It is shown back to an administrator, who hands it to the
 * integrator, and can be generated here.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The fields.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function paymentCallbackFields(t) {
	return [
		{
			key: 'callback-secret-missing',
			type: 'status',
			tone: 'warning',
			text: t(
				'dossiq',
				'No callback secret is configured. Every dwangsom payment-confirmation callback is currently being rejected with HTTP 401.',
			),
			visibleWhen: { key: 'dwangsom_callback_secret', empty: true },
		},
		{
			key: 'dwangsom_callback_secret',
			label: t('dossiq', 'Dwangsom callback secret'),
			type: 'password',
			hint: t(
				'dossiq',
				'Shared HMAC-SHA256 signing secret. Provide this value to the ERP or Integriq integrator so it can sign X-Procest-Signature headers.',
			),
		},
		{
			key: 'generate-callback-secret',
			label: t('dossiq', 'Generate random secret'),
			type: 'action',
			fill: { key: 'dwangsom_callback_secret', bytes: 32 },
		},
	]
}

/**
 * The store registry: the OpenRegister instance the Store page browses, its
 * register and the write-only token.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The fields.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function storeRegistryFields(t) {
	return [
		{
			key: 'store-offline',
			type: 'status',
			text: t(
				'dossiq',
				'A store registry is another OpenRegister instance that publishes case configuration. Leave the address empty to keep dossiq offline: nothing is requested from the network until one is set.',
			),
		},
		{
			key: 'registryUrl',
			label: t('dossiq', 'Registry address'),
			placeholder: 'https://registry.example.org',
		},
		{
			key: 'registryRegister',
			label: t('dossiq', 'Register on that instance'),
			default: 'dossiq',
		},
		{
			key: 'registryToken',
			label: t('dossiq', 'Registry token'),
			type: 'secret',
			setKey: 'registryTokenSet',
			hint: t(
				'dossiq',
				'The token is never shown again after saving. Leave it empty to keep the one already stored.',
			),
		},
	]
}

/**
 * An ISO 8601 duration: P, then years to days, then T and hours to seconds,
 * with at least one part.
 *
 * @type {string}
 */
export const ISO_DURATION_PATTERN =
	'^P(?!$)(\\d+Y)?(\\d+M)?(\\d+W)?(\\d+D)?(T(?=\\d)(\\d+H)?(\\d+M)?(\\d+S)?)?$'

/**
 * Archiving: how often a reviewer is reminded of the archival decisions they
 * hold. The setting is OpenRegister's, read and written on its own endpoint;
 * dossiq keeps no copy.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The fields.
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */
export function archivalFields(t) {
	const hint = t(
		'dossiq',
		'An ISO 8601 duration, for example P7D for every seven days.',
	)
	return [
		{
			key: 'reviewReminderFrequency',
			label: t('dossiq', 'Review reminder frequency'),
			hint,
			required: true,
			default: 'P7D',
			pattern: ISO_DURATION_PATTERN,
			patternMessage: hint,
		},
	]
}
