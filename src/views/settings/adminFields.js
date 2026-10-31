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
			// The lists of contacts and organisations open on this city
			// (`@config.municipalityCity`); empty leaves that lens out.
			key: 'municipality_city',
			label: t('dossiq', 'City of the municipality'),
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
			hideLabel: true,
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
		},
		{
			key: 'allowedWebsites',
			label: t('dossiq', 'Allowed websites (CORS)'),
			type: 'note',
			text: t(
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
				// Off is one of the sources, so the choice is never empty: no clear button.
				required: true,
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
 * connection is green, a failing one red, a simulated one the info blue, an
 * unavailable one amber, the rest neutral.
 *
 * @type {Readonly<Record<string, string>>}
 * @spec openspec/specs/admin-settings/spec.md
 */
export const CONNECTION_STATUS_TONES = Object.freeze({
	configured: 'success',
	error: 'error',
	simulated: 'info',
	unavailable: 'warning',
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
			label: t('dossiq', 'Menu'),
			type: 'radio',
			default: 'simple',
			options: [
				{
					value: 'simple',
					label: t('dossiq', 'Simple'),
					description: t('dossiq', 'Dashboard, My work, cases and relations'),
				},
				{
					value: 'full',
					label: t('dossiq', 'Full'),
					description: t('dossiq', 'Also reports, administration and every module'),
				},
			],
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
			key: 'consultation_default_deadline_days',
			label: t('dossiq', 'Standard term in days'),
			type: 'number',
			integer: true,
			min: 0,
			default: 28,
		},
		{
			key: 'consultation_warning_offset_days',
			label: t('dossiq', 'Warn, days ahead'),
			type: 'number',
			integer: true,
			min: 0,
			default: 5,
		},
		{
			key: 'consultation_external_response_url',
			label: t('dossiq', 'Address for external responses'),
			placeholder: 'https://dossiq.example.org/consultation/respond/',
			pattern: '^https://',
			patternMessage: t('dossiq', 'Starts with https://'),
		},
		{
			key: 'consultation_bottleneck_threshold',
			advanced: true,
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
			key: 'reviewerThroughputReaders',
			label: t('dossiq', 'Reader group for the throughput report'),
			hint: t(
				'dossiq',
				'The id of an existing Nextcloud group. Administrators outside it cannot read the report either.',
			),
		},
		{
			key: 'reviewerThroughputReport',
			label: t('dossiq', 'Report throughput per reviewer per day'),
			type: 'switch',
			trueValue: 'true',
			falseValue: 'false',
		},
		{
			key: 'collectionPartiesReport',
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
				'No callback secret is configured. Every payout confirmation callback is currently being rejected with HTTP 401.',
			),
			visibleWhen: { key: 'payout_callback_secret', empty: true },
		},
		{
			key: 'payout_callback_secret',
			label: t('dossiq', 'Payout callback secret'),
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
			fill: { key: 'payout_callback_secret', bytes: 32 },
			// Beside Save, as the board draws it (nc-vue `placement: 'save'`).
			placement: 'save',
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

/**
 * The KCC bridge behaviour: how a caller is identified, what the case summary
 * holds, how often sentiment and availability are read, when a call plan
 * overflows, and the words that flag negative sentiment. The defaults are the
 * server's (SettingsService::KCC_DEFAULTS).
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The fields.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function contactCentreFields(t) {
	const number = (key, label, fallback, hint = '') => ({
		key,
		label,
		type: 'number',
		min: 0,
		default: fallback,
		placeholder: String(fallback),
		...(hint ? { hint } : {}),
	})
	return [
		{
			key: 'kcc-scope',
			type: 'status',
			text: t(
				'dossiq',
				'Configure how the KCC-werkplek bridge identifies burgers, opens the case-voorblad, scores sentiment, and routes calls. DigiD authentication and the telephony screen-pop are delivered by Integriq and pipelinq respectively; only the Dossiq-side behaviour is configured here.',
			),
		},
		{
			key: 'identification_method',
			label: t('dossiq', 'Identification method'),
			type: 'select',
			required: true,
			default: 'both',
			options: [
				{ value: 'digid', label: 'DigiD' },
				{ value: 'bsn_questions', label: t('dossiq', 'Identification questions') },
				{ value: 'both', label: t('dossiq', 'Both') },
			],
			hint: t(
				'dossiq',
				'Whether burgers are identified via DigiD (portaal/chat), identificatievragen (telefoon), or both.',
			),
		},
		{
			...number(
				'identification_score_threshold',
				t('dossiq', 'Identification score threshold (0.6 - 1.0)'),
				0.8,
				t(
					'dossiq',
					'Minimum identificatievragen match score to link a burger and reveal full zaaksinfo. Below the threshold, only openbare zaaksinformatie is shown.',
				),
			),
			max: 1,
		},
		number('sentiment_polling_interval', t('dossiq', 'Sentiment polling interval (seconds)'), 5),
		number('specialist_availability_polling_interval', t('dossiq', 'Specialist availability polling interval (seconds)'), 30),
		number('max_zaken_voorblad', t('dossiq', 'Max open zaken in voorblad'), 10),
		number('max_contactmomenten_history', t('dossiq', 'Max contactmomenten in history'), 5),
		number('belplan_overflow_threshold_wachttijd', t('dossiq', 'Belplan overflow threshold: wachttijd (seconds)'), 180),
		number('belplan_overflow_threshold_wachtrij_lengte', t('dossiq', 'Belplan overflow threshold: wachtrij lengte'), 5),
		{
			key: 'sentiment_trigger_words',
			label: t('dossiq', 'Sentiment trigger words (one per line)'),
			type: 'lines',
			saveAs: 'json',
			rows: 5,
			wide: true,
			placeholder: 'klacht\nadvocaat\nwethouder\nmedia',
			hint: t(
				'dossiq',
				'Dutch words that flag negative sentiment and trigger an escalation recommendation. One word or phrase per line.',
			),
		},
	]
}

/**
 * The group the background service account joins.
 *
 * @type {string}
 */
export const BACKGROUND_SERVICE_GROUP = 'dossiq-background-service'

/**
 * The account background jobs save as: its user name, and a warning naming
 * what is wrong with it while it cannot be used.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The fields.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function backgroundAccountFields(t) {
	const until = t(
		'dossiq',
		'Until you choose one, reminders on suspended terms are not sent and nothing is saved.',
	)
	return [
		{
			key: 'reason',
			type: 'status',
			tone: 'warning',
			visibleWhen: { key: 'usable', equals: false },
			textMap: {
				unknown: `${t('dossiq', 'The chosen account does not exist.')} ${until}`,
				disabled: `${t('dossiq', 'The chosen account is disabled.')} ${until}`,
				'not-in-group': `${t('dossiq', 'The chosen account is not in the group {group}.', { group: BACKGROUND_SERVICE_GROUP })} ${until}`,
				'*': `${t('dossiq', 'No account is chosen.')} ${until}`,
			},
		},
		{
			key: 'userId',
			label: t('dossiq', 'Nextcloud user name'),
			required: true,
			hint: t(
				'dossiq',
				'The account joins the group {group}. Pick an account nobody signs in with.',
				{ group: BACKGROUND_SERVICE_GROUP },
			),
		},
	]
}

/**
 * AI-assisted processing: the global switch, the model, the features, the
 * privacy acknowledgements and a connection test. Everything after the
 * global switch shows only while it is on.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The fields.
 * @spec openspec/specs/ai-assistance/spec.md
 */
export function aiFields(t) {
	const on = { key: 'ai_enabled', equals: true }
	const cloud = { key: 'ai_model_type', equals: 'cloud' }
	const features = [
		['ai_feature_classification', t('dossiq', 'Document classification')],
		['ai_feature_extraction', t('dossiq', 'Data extraction')],
		['ai_feature_qa', t('dossiq', 'Knowledge base Q&A')],
		['ai_feature_summary', t('dossiq', 'Auto-summarization')],
		['ai_feature_routing', t('dossiq', 'Routing suggestions')],
		['ai_feature_decision_support', t('dossiq', 'Decision support')],
	]
	const fields = [
		{ key: 'ai_enabled', label: t('dossiq', 'Switch on AI-assisted processing'), type: 'switch' },
		{
			key: 'ai_model_type',
			label: t('dossiq', 'Kind of model'),
			type: 'radio',
			default: 'local',
			options: [
				{ value: 'local', label: t('dossiq', 'Local (Ollama)'), description: t('dossiq', 'Data stays on your own servers') },
				{ value: 'cloud', label: t('dossiq', 'Cloud'), description: t('dossiq', 'A provider outside your organisation') },
			],
			visibleWhen: on,
		},
		{
			key: 'cloud-warning',
			type: 'status',
			tone: 'warning',
			text: t(
				'dossiq',
				'Warning: Case data will be sent to an external service. Ensure this complies with your data processing agreements.',
			),
			visibleWhen: [on, cloud],
		},
		{ key: 'ai_model_url', label: t('dossiq', 'Address of the model'), visibleWhen: on },
		{ key: 'ai_model_name', label: t('dossiq', 'Model name'), placeholder: 'llama3.1', visibleWhen: on },
		{
			key: 'ai_api_key',
			label: t('dossiq', 'API Key'),
			type: 'secret',
			setKey: 'ai_api_key_set',
			visibleWhen: [on, cloud],
		},
		{ key: 'heading-features', label: t('dossiq', 'Features'), type: 'heading', advanced: true, visibleWhen: on },
		...features.map(([key, label]) => ({ key, label, type: 'switch', advanced: true, visibleWhen: on })),
		{ key: 'heading-privacy', label: t('dossiq', 'Privacy and compliance'), type: 'heading', advanced: true, visibleWhen: on },
		{
			key: 'ai_pii_stripping',
			label: t('dossiq', 'Strip PII (BSN, financial data) from AI prompts'),
			type: 'switch',
			advanced: true,
			visibleWhen: on,
		},
		{
			key: 'ai_dpia_acknowledged',
			label: t('dossiq', 'DPIA (Data Protection Impact Assessment) has been completed'),
			type: 'switch',
			advanced: true,
			visibleWhen: on,
		},
		{
			key: 'dpia-warning',
			type: 'status',
			tone: 'warning',
			text: t(
				'dossiq',
				'A DPIA is required before you use AI features with personal data. Until you acknowledge it here, every AI feature stays off.',
			),
			visibleWhen: [on, { key: 'ai_dpia_acknowledged', equals: false }],
		},
		{ key: 'heading-test', label: t('dossiq', 'Connection test'), type: 'heading', visibleWhen: on },
		{
			key: 'test-connection',
			label: t('dossiq', 'Test connection'),
			type: 'action',
			url: '/apps/dossiq/api/ai/health',
			okPath: 'healthy',
			visibleWhen: on,
		},
	]
	// The card lays its fields out in two columns, model address beside model
	// name as board DqBeheerinstellingen draws them; a switch or a choice
	// keeps a row of its own.
	return fields.map((field) => (field.type === 'switch' || field.type === 'radio' ? { ...field, wide: true } : field))
}

/**
 * The daily digest a person gets: on or off, the hour, and who decided it.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The fields.
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export function workDigestFields(t) {
	return [
		{
			key: 'enabled',
			label: t('dossiq', 'Send me a daily digest of my open work'),
			type: 'switch',
			default: true,
		},
		{
			key: 'hour',
			label: t('dossiq', 'Hour of the day'),
			type: 'select',
			options: Array.from({ length: 24 }, (_, hour) => ({
				value: hour,
				label: t('dossiq', '{hour}:00', { hour: String(hour).padStart(2, '0') }),
			})),
			default: 8,
			visibleWhen: { key: 'enabled', equals: true },
		},
		{
			key: 'source',
			type: 'status',
			textMap: {
				'user-override': t('dossiq', 'You set this.'),
				'group-default': t('dossiq', 'Your team set this. You can decide for yourself.'),
				'schema-default': t('dossiq', 'Nobody has changed this, so it is on the setting it ships with.'),
				'*': t('dossiq', 'This instance keeps the setting here rather than with your other notifications.'),
			},
		},
		{
			key: 'nothing-waiting',
			type: 'status',
			text: t('dossiq', 'A day with nothing waiting sends nothing.'),
		},
	]
}

/**
 * The case number pattern used while none is set, shown as the placeholder so
 * an empty field says what it means. Mirrors CaseNumberRecognizer::DEFAULT_PATTERN.
 *
 * @type {string}
 */
export const DEFAULT_CASE_PATTERN =
	'/(?<![\\w-])(?:\\[)?(?:[A-Z]{2,10}-)?((?:19|20)\\d{2}-\\d{4,6})(?:\\])?(?![\\w-])/u'

/**
 * Where an instance's email-to-case matching is read and saved; it checks the
 * pattern before anything is stored.
 *
 * @type {string}
 */
const CASE_MATCHING_URL = '/apps/dossiq/api/settings/email-case-matching/instance'

/**
 * Where dossiq's own settings are read and saved.
 *
 * @type {string}
 */
const DOSSIQ_SETTINGS_URL = '/apps/dossiq/api/settings'

/**
 * The shared mailbox: the Mail account and folder intake reads, how often and
 * how much, what unclaimed mail becomes, matching mail to cases by number,
 * the sender of dossiq's own mail and who may receive it, and who runs and
 * may not feed intake. Intake keys live on the mailbox endpoint, matching on
 * its own endpoint, the sender on dossiq's settings. The six fields
 * DqBeheerinstellingen draws come first; the rest are `advanced`, behind one
 * disclosure.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The fields.
 * @spec openspec/specs/case-email-integration/spec.md
 */
export function sharedMailboxFields(t) {
	const own = (key) => ({
		url: DOSSIQ_SETTINGS_URL,
		valuePath: `config.${key}`,
		saveMethod: 'PUT',
	})
	return [
		{
			key: 'mailbox-explanation',
			type: 'status',
			advanced: true,
			foldable: true,
			text: t(
				'dossiq',
				'Configure the shared functional mailbox (e.g. zaken@gemeente.nl) that the inbound poller ingests and auto-links to cases by [ZAAK-YYYY-NNNNNN] subject tag. Per-user mail accounts stay in Nextcloud Mail. The outbound fields below cover only the mail dossiq sends itself. That is workflow actions and the case email screen.',
			),
		},
		{
			key: 'email_mail_account_id',
			label: t('dossiq', 'Intake mail account'),
			type: 'select',
			optionsUrl: '/apps/dossiq/api/settings/email/mail-accounts',
			optionsPath: 'accounts',
			optionLabel: 'name',
			optionValue: 'id',
			optionDetail: 'email',
			placeholder: t('dossiq', 'Pick the mailbox intake reads'),
			hint: t(
				'dossiq',
				'Nextcloud Mail keeps the account and the password. Pick the mailbox dossiq reads. Dossiq stores no password of its own.',
			),
		},
		{
			key: 'mail-app-missing',
			type: 'status',
			tone: 'warning',
			url: '/apps/dossiq/api/settings/email/mail-accounts',
			valuePath: 'available',
			textMap: {
				false: t(
					'dossiq',
					'The Mail app is not installed, so intake reads nothing. Your messages stay in the mailbox and none are lost. Install Mail to start reading them.',
				),
				'*': '',
			},
		},
		{
			key: 'email_imap_folder',
			// The section translates a label itself. Translated here too, the
			// Dutch "Map" would be read as the key "Map" and show "Kaart".
			label: 'Folder',
			placeholder: 'INBOX',
			default: 'INBOX',
		},
		{
			key: 'email_transport',
			advanced: true,
			label: t('dossiq', 'Transport / source mailbox account'),
			placeholder: t('dossiq', 'Nextcloud Mail account or functional mailbox id'),
			hint: t(
				'dossiq',
				'Which Nextcloud Mail account or functional mailbox is the case-correspondence source. No per-user SMTP send credentials are configured here.',
			),
		},
		{
			key: 'email_poll_interval',
			label: t('dossiq', 'Fetch every (seconds)'),
			type: 'number',
			integer: true,
			min: 0,
			default: 300,
		},
		{
			key: 'email_poll_batch_size',
			label: t('dossiq', 'Messages per fetch'),
			type: 'number',
			integer: true,
			min: 0,
			default: 50,
		},
		{
			key: 'email_fallback_case_type',
			label: t('dossiq', 'Case type for mail nobody claims'),
			type: 'select',
			optionsUrl: '/apps/openregister/api/objects/dossiq/caseType?_limit=100&isDraft=false',
			optionsPath: 'results',
			optionLabel: 'title',
			optionValue: 'id',
			placeholder: t('dossiq', 'Leave it in the mailbox'),
			hint: t(
				'dossiq',
				'A mail whose subject carries no case number, or one that no longer exists, becomes a case of this type. Leave it empty and the mail stays unread in the mailbox, which is what happens today.',
			),
		},
		{
			key: 'email_case_matching_enabled',
			label: t('dossiq', 'Link mail to cases by case number'),
			type: 'switch',
			trueValue: 'yes',
			falseValue: 'no',
			url: CASE_MATCHING_URL,
			saveMethod: 'PUT',
			wide: true,
			hint: t(
				'dossiq',
				'Each user still switches it on for their own mailbox. Until then, nothing is linked.',
			),
		},
		{
			key: 'email_case_matching_pattern',
			advanced: true,
			label: t('dossiq', 'Case number pattern'),
			url: CASE_MATCHING_URL,
			saveMethod: 'PUT',
			placeholder: DEFAULT_CASE_PATTERN,
			hint: `${t(
				'dossiq',
				'Leave it empty to match numbers like 2026-0042 and [ZAAK-2026-0042]. The first group in the pattern must capture the case number.',
			)} ${t(
				'dossiq',
				'Only the subject and the start of each message are read. A case number further down is missed.',
			)}`,
		},
		{
			key: 'email_from_address',
			advanced: true,
			label: t('dossiq', 'Sender address'),
			placeholder: 'zaken@gemeente.nl',
			...own('email_from_address'),
			hint: t(
				'dossiq',
				'The address term notices and service mail are sent from. Leave it empty and dossiq does not send them. Mail a handler writes about a case leaves from the Nextcloud Mail account.',
			),
		},
		{
			key: 'email_from_name',
			advanced: true,
			label: t('dossiq', 'Sender name'),
			placeholder: 'Gemeente Voorbeeld',
			...own('email_from_name'),
		},
		{
			key: 'email_recipient_allowlist',
			advanced: true,
			label: t('dossiq', 'Allowed recipients'),
			placeholder: '@gemeente.nl, team@gemeente.nl',
			...own('email_recipient_allowlist'),
			hint: t(
				'dossiq',
				'List the addresses and domains dossiq may send case mail to. Leave it empty and only your own domain is allowed, taken from the sender address. Write * to allow every recipient.',
			),
		},
		{
			key: 'email_intake_role',
			advanced: true,
			label: t('dossiq', 'Group that runs intake'),
			placeholder: 'zaakbehandelaars',
			hint: t(
				'dossiq',
				'Only this group and administrators read the intake log. Only they release a held message. The log keeps the original of every message the mailbox received. Leave it empty and only administrators can read it.',
			),
		},
		{
			key: 'email_intake_blocklist',
			advanced: true,
			label: t('dossiq', 'Senders that may not open a case'),
			placeholder: 'spam@voorbeeld.nl, @voorbeeld.nl',
			hint: t(
				'dossiq',
				'List addresses, or whole domains written as @voorbeeld.nl. A blocked sender opens no case by mail. They can still mail a colleague. The allow half of this list is Nextcloud Mail’s trusted senders.',
			),
		},
		{
			key: 'email_intake_junk_rules',
			advanced: true,
			label: t('dossiq', 'Junk rules'),
			type: 'textarea',
			rows: 3,
			wide: true,
			placeholder: 'X-Spam-Status: Yes',
			hint: t(
				'dossiq',
				'Write one rule per line: a header name, a colon, the text to find. Plain text on its own is matched against the subject. A message a rule calls junk is held, never deleted. The intake log names the rule that held it.',
			),
		},
	]
}

/**
 * The register dossiq keeps its records in, and the schema per kind of
 * record. The keys are the app config keys that hold the OpenRegister ids:
 * `register`, and `<slug>_schema` per kind.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The groups.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function registerMappingGroups(t) {
	return [
		{
			name: t('dossiq', 'Configuration'),
			registerConfigKey: 'register',
			types: [
				{ slug: 'case', label: t('dossiq', 'Case schema') },
				{ slug: 'case_type', label: t('dossiq', 'Case type schema') },
				{ slug: 'status', label: t('dossiq', 'Status schema') },
				{ slug: 'role', label: t('dossiq', 'Role schema') },
				{ slug: 'result', label: t('dossiq', 'Result schema') },
				{ slug: 'decision', label: t('dossiq', 'Decision schema') },
				{ slug: 'status_type', label: t('dossiq', 'Status type schema') },
			],
		},
	]
}
