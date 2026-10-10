<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Gather documents for a Woo request.

  A Woo request asks for every document on a subject, wherever it sits. The
  handler searches the sources the instance has in one go and adds what they
  pick to the case, where it becomes a document waiting for assessment.

  The two platform sources are searched here, through Nextcloud's unified
  search, so each answers with the handler's own access (design D-1, D-2): the
  `files` provider, and OpenRegister's `openregister_objects` provider narrowed
  to the document schema. Which provider and which filters is the server's
  answer from GET /woo/sources, not a guess in this file. integriq's Microsoft
  365 source goes through dossiq's own search endpoint.

  A source that cannot answer says why. It is never shown as an empty result.

  @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Gather documents')"
		size="large"
		data-testid="gather-documents-dialog"
		@closing="$emit('close')">
		<div class="gather-documents">
			<p class="gather-documents__explainer">
				{{
					t(
						'dossiq',
						'Search the sources for documents about this request. What you add lands on the case and waits for assessment.',
					)
				}}
			</p>

			<p
				v-if="planLoaded && custodians.length === 0"
				class="gather-documents__error"
				data-testid="gather-no-plan"
				role="alert">
				{{ t('dossiq', 'Record the search plan before collecting') }}
			</p>

			<NcSelect
				v-else-if="custodians.length > 0"
				v-model="custodian"
				data-testid="gather-custodian"
				:options="custodians"
				:inputLabel="t('dossiq', 'Whose files are these')"
				:clearable="false" />

			<NcTextField
				v-model="terms"
				data-testid="gather-terms"
				:label="t('dossiq', 'Search terms')" />

			<div class="gather-documents__period">
				<NcTextField
					v-model="from"
					type="date"
					data-testid="gather-from"
					:label="t('dossiq', 'From')" />
				<NcTextField
					v-model="to"
					type="date"
					data-testid="gather-to"
					:label="t('dossiq', 'To')" />
			</div>

			<fieldset class="gather-documents__sources" data-testid="gather-sources">
				<legend>{{ t('dossiq', 'Sources') }}</legend>
				<div
					v-for="source in sources"
					:key="source.id"
					class="gather-documents__source">
					<NcCheckboxRadioSwitch
						:modelValue="chosen.includes(source.id)"
						:disabled="!source.available"
						:data-testid="`gather-source-${source.id}`"
						@update:modelValue="toggle(source.id, $event)">
						{{ source.label }}
					</NcCheckboxRadioSwitch>
					<p
						v-if="!source.available"
						class="gather-documents__muted"
						:data-testid="`gather-source-reason-${source.id}`">
						{{ reasonText(source.reason) }}
					</p>
				</div>
			</fieldset>

			<section
				v-for="group in groups"
				:key="group.id"
				class="gather-documents__group"
				:data-testid="`gather-results-${group.id}`">
				<h3>{{ group.label }}</h3>
				<p v-if="group.error" class="gather-documents__error" role="alert">
					{{ group.error }}
				</p>
				<p
					v-else-if="group.rows.length === 0"
					class="gather-documents__muted">
					{{ t('dossiq', 'Nothing found in this source.') }}
				</p>
				<ul v-else class="gather-documents__rows">
					<li v-for="row in group.rows" :key="`${group.id}:${row.key}`">
						<NcCheckboxRadioSwitch
							:modelValue="isPicked(group.id, row.key)"
							:data-testid="`gather-pick-${group.id}-${row.key}`"
							@update:modelValue="pick(group.id, row, $event)">
							<span class="gather-documents__name">{{
								row.name
							}}</span>
							<span class="gather-documents__muted">{{
								[row.location, row.date].filter(Boolean).join(' · ')
							}}</span>
							<span
								v-if="row.snippet"
								class="gather-documents__snippet"
								>{{ row.snippet }}</span
							>
						</NcCheckboxRadioSwitch>
					</li>
				</ul>
				<p v-if="group.remaining > 0" class="gather-documents__muted">
					{{
						n(
							'dossiq',
							'%n more result. Narrow your terms to see it.',
							'%n more results. Narrow your terms to see them.',
							group.remaining,
						)
					}}
				</p>
				<p
					v-for="notice in group.notices"
					:key="notice"
					class="gather-documents__muted">
					{{ noticeText(notice) }}
				</p>
			</section>

			<ul
				v-if="refusals.length > 0"
				class="gather-documents__refusals"
				data-testid="gather-refusals">
				<li
					v-for="refusal in refusals"
					:key="`${refusal.source}:${refusal.key}`"
					role="alert">
					{{ refusal.name }}: {{ refusal.message }}
				</li>
			</ul>

			<p
				v-if="error"
				class="gather-documents__error"
				data-testid="gather-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="gather-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Close') }}
			</NcButton>
			<NcButton
				data-testid="gather-search"
				:disabled="!canSearch"
				@click="search">
				{{ t('dossiq', 'Search') }}
			</NcButton>
			<NcButton
				data-testid="gather-add"
				variant="primary"
				:disabled="busy || picks.length === 0"
				@click="add">
				{{ t('dossiq', 'Add selected') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const PAGE_REFRESH = 'cn:page:refresh'
const ROW_LIMIT = 50
const UUID = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/gi

/**
 * The uuid a unified search entry links to: the last one in its resource url.
 *
 * @param {object} entry A unified search entry.
 * @return {string} The uuid, or ''.
 */
function uuidOf(entry) {
	const found = String(entry?.resourceUrl ?? '').match(UUID)
	return found ? found[found.length - 1] : ''
}

/**
 * One unified search entry as a dialog row.
 *
 * @param {string} source The source id.
 * @param {object} entry The entry.
 * @return {object} `{key, name, location, date, snippet}`.
 */
function rowOf(source, entry) {
	const attributes = entry?.attributes ?? {}
	const key = source === 'files' ? String(attributes.fileId ?? '') : uuidOf(entry)
	return {
		key,
		name: String(entry?.title ?? ''),
		location: String(attributes.path ?? entry?.subline ?? ''),
		date: '',
		snippet: source === 'files' ? '' : String(entry?.subline ?? ''),
	}
}

export default {
	name: 'GatherDocumentsDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The Woo case. Absent when the manifest opened the dialog: the route answers. */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			terms: '',
			from: '',
			to: '',
			sources: [],
			chosen: [],
			groups: [],
			picks: [],
			searchedTerms: '',
			refusals: [],
			error: '',
			busy: false,
			custodians: [],
			custodian: '',
			planLoaded: false,
		}
	},

	computed: {
		/** @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012 */
		targetCaseId() {
			const given = this.caseId.startsWith('@') ? '' : this.caseId
			return given || String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012 */
		canSearch() {
			return (
				!this.busy
				&& this.custodians.length > 0
				&& this.terms.trim() !== ''
				&& this.chosen.length > 0
			)
		},
	},

	/** @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012 */
	async mounted() {
		await Promise.all([this.loadSources(), this.loadPlan()])
	},

	methods: {
		t,
		n,

		/**
		 * Read which sources there are, and tick every one that can answer.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
		 */
		async loadSources() {
			try {
				const { data } = await axios.get(this.endpoint(''))
				this.sources = Array.isArray(data?.sources) ? data.sources : []
				this.chosen = this.sources
					.filter((source) => source.available)
					.map((source) => source.id)
			} catch (error) {
				this.error =
					error?.response?.data?.message
					|| t('dossiq', 'The sources could not be read.')
			}
		},

		/**
		 * The recorded search plan: its custodians, period and terms prefill the search.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
		 */
		async loadPlan() {
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/dossiq/api/cases/${encodeURIComponent(this.targetCaseId)}/woo/plan`,
					),
				)
				const plan = data?.recorded ? data.plan : null
				this.custodians = (plan?.custodians ?? [])
					.map((custodian) => String(custodian?.name ?? ''))
					.filter(Boolean)
				this.custodian = this.custodians[0] ?? ''
				this.terms = this.terms || String(plan?.terms ?? '')
				this.from = this.from || String(plan?.periodFrom ?? '')
				this.to = this.to || String(plan?.periodTo ?? '')
			} catch {
				this.custodians = []
			} finally {
				this.planLoaded = true
			}
		},

		/**
		 * Store a platform search as a query someone else can re-run.
		 *
		 * @param {object} source The source.
		 * @param {Array<object>} rows The rows it answered.
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-selecting-query-is-saved-and-can-be-re-run-req-wrc-004
		 */
		async recordQuery(source, rows) {
			try {
				await axios.post(
					generateUrl(
						`/apps/dossiq/api/cases/${encodeURIComponent(this.targetCaseId)}/woo/collection/queries`,
					),
					{
						source: source.id,
						terms: this.searchedTerms,
						periodFrom: this.from,
						periodTo: this.to,
						resultKeys: rows.map((row) => row.key),
					},
				)
			} catch {
				// The search still answered; a query that could not be stored is not a failed search.
			}
		},

		/**
		 * A dossiq url for this case's Woo sources.
		 *
		 * @param {string} tail What comes after `/woo/sources`.
		 * @return {string} The url.
		 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
		 */
		endpoint(tail) {
			return generateUrl(
				`/apps/dossiq/api/cases/${encodeURIComponent(this.targetCaseId)}/woo/sources${tail}`,
			)
		},

		/**
		 * The sentence for a source that cannot answer.
		 *
		 * @param {string} reason The server's reason code.
		 * @return {string} The sentence.
		 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
		 */
		reasonText(reason) {
			if (reason === 'integriq-not-installed') {
				return t('dossiq', 'Not connected: integriq is not installed.')
			}
			if (reason === 'search-not-offered') {
				return t(
					'dossiq',
					'Not connected: this integriq version cannot search.',
				)
			}
			return t('dossiq', 'Not connected.')
		},

		/**
		 * The sentence for a notice a source answered with.
		 *
		 * @param {string} notice The notice code.
		 * @return {string} The sentence.
		 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
		 */
		noticeText(notice) {
			if (notice === 'delegated-grant-missing') {
				return t(
					'dossiq',
					'Mail and chat were not searched: connect your own Microsoft account first.',
				)
			}
			return notice
		},

		/**
		 * Tick or untick a source.
		 *
		 * @param {string} id The source id.
		 * @param {boolean} on Whether it is ticked.
		 * @return {void}
		 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
		 */
		toggle(id, on) {
			this.chosen = on
				? [...new Set([...this.chosen, id])]
				: this.chosen.filter((chosen) => chosen !== id)
		},

		/**
		 * Search every ticked source at once, each answering on its own.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
		 */
		async search() {
			if (!this.canSearch) {
				return
			}
			this.busy = true
			this.error = ''
			this.refusals = []
			this.picks = []
			this.searchedTerms = this.terms.trim()
			const chosen = this.sources.filter(
				(source) => source.available && this.chosen.includes(source.id),
			)
			this.groups = await Promise.all(
				chosen.map((source) => this.searchOne(source)),
			)
			this.busy = false
		},

		/**
		 * Search one source.
		 *
		 * @param {object} source The source as GET /woo/sources answered it.
		 * @return {Promise<object>} `{id, label, rows, remaining, notices, error}`.
		 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
		 */
		async searchOne(source) {
			const group = {
				id: source.id,
				label: source.label,
				rows: [],
				remaining: 0,
				notices: [],
				error: '',
			}
			try {
				if (source.kind === 'integriq') {
					const { data } = await axios.post(this.endpoint('/search'), {
						source: source.id,
						terms: this.searchedTerms,
						from: this.from,
						to: this.to,
					})
					group.rows = Array.isArray(data?.rows) ? data.rows : []
					group.remaining = Number(data?.remaining ?? 0)
					group.notices = Array.isArray(data?.notices) ? data.notices : []
					return group
				}

				const params = {
					term: this.searchedTerms,
					limit: ROW_LIMIT,
					...(source.search?.filters ?? {}),
				}
				if (this.from) {
					params.since = this.from
				}
				if (this.to) {
					params.until = this.to
				}
				const provider = encodeURIComponent(source.search?.provider ?? '')
				const { data } = await axios.get(
					generateOcsUrl(`search/providers/${provider}/search`),
					{ params },
				)
				const entries = data?.ocs?.data?.entries ?? []
				group.rows = entries
					.map((entry) => rowOf(source.id, entry))
					.filter((row) => row.key !== '')
				await this.recordQuery(source, group.rows)
			} catch (error) {
				group.error =
					error?.response?.data?.message
					|| t('dossiq', 'This source could not be searched.')
			}
			return group
		},

		/**
		 * Whether a row is picked.
		 *
		 * @param {string} source The source id.
		 * @param {string} key The row key.
		 * @return {boolean} True when picked.
		 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
		 */
		isPicked(source, key) {
			return this.picks.some(
				(pick) => pick.source === source && pick.key === key,
			)
		},

		/**
		 * Pick or unpick a row.
		 *
		 * @param {string} source The source id.
		 * @param {object} row The row.
		 * @param {boolean} on Whether it is picked.
		 * @return {void}
		 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
		 */
		pick(source, row, on) {
			this.picks = this.picks.filter(
				(pick) => !(pick.source === source && pick.key === row.key),
			)
			if (on) {
				this.picks.push({
					source,
					custodian: this.custodian,
					key: row.key,
					location: row.location,
					name: row.name,
				})
			}
		},

		/**
		 * Add the picks to the case; keep the dialog open on what was refused.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
		 */
		async add() {
			if (this.busy || this.picks.length === 0) {
				return
			}
			this.busy = true
			this.error = ''
			this.refusals = []
			const names = Object.fromEntries(
				this.picks.map((pick) => [`${pick.source}:${pick.key}`, pick.name]),
			)
			try {
				const { data } = await axios.post(this.endpoint('/add'), {
					terms: this.searchedTerms,
					picks: this.picks.map(
						({ source, custodian, key, location }) => ({
							source,
							custodian,
							key,
							location,
						}),
					),
				})
				this.readResults(data, names)
			} catch (error) {
				const data = error?.response?.data
				if (Array.isArray(data?.results)) {
					this.readResults(data, names)
				} else {
					this.error =
						data?.message || t('dossiq', 'The documents were not added.')
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * Read the per-pick answer: drop what was added, list what was refused.
		 *
		 * @param {object} data The add answer.
		 * @param {object} names Pick names by `source:key`.
		 * @return {void}
		 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
		 */
		readResults(data, names) {
			const results = Array.isArray(data?.results) ? data.results : []
			const refused = results.filter((result) => result.status !== 'added')
			this.refusals = refused.map((result) => ({
				...result,
				name: names[`${result.source}:${result.key}`] || result.key,
			}))
			this.picks = this.picks.filter((pick) =>
				refused.some(
					(result) =>
						result.source === pick.source && result.key === pick.key,
				),
			)
			if (results.some((result) => result.status !== 'refused')) {
				emit(PAGE_REFRESH, {})
			}
			if (refused.length === 0) {
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped>
.gather-documents {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 16px 16px;
}

.gather-documents__period {
	display: flex;
	gap: 12px;
}

.gather-documents__sources {
	border: none;
	margin: 0;
	padding: 0;
}

.gather-documents__rows,
.gather-documents__refusals {
	list-style: none;
	margin: 0;
	padding: 0;
}

.gather-documents__name {
	display: block;
	font-weight: 600;
}

.gather-documents__snippet {
	display: block;
}

.gather-documents__muted {
	display: block;
	color: var(--color-text-maxcontrast);
}

.gather-documents__error,
.gather-documents__refusals {
	color: var(--color-error-text, var(--color-error));
}
</style>
