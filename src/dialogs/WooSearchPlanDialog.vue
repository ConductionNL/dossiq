<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The search plan of a Woo request.

  Before anyone searches, the plan says whose files and which systems are
  searched, over which period and with which terms. Gather documents refuses
  to search or add until a plan is recorded. Recording again changes the same
  plan, and the history below the form is that plan's OpenRegister audit
  trail, so a decision can show who planned what and when.

  @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Search plan')"
		size="normal"
		data-testid="woo-search-plan-dialog"
		@closing="$emit('close')">
		<div class="woo-search-plan">
			<p class="woo-search-plan__explainer">
				{{
					t(
						'dossiq',
						'Record whose files and which systems you search, for which period and with which terms. Gather documents starts once the plan is recorded.',
					)
				}}
			</p>

			<p
				v-if="recordedLine"
				class="woo-search-plan__muted"
				data-testid="woo-plan-recorded">
				{{ recordedLine }}
			</p>

			<fieldset
				class="woo-search-plan__group"
				data-testid="woo-plan-custodians">
				<legend>{{ t('dossiq', 'Whose files') }}</legend>
				<div
					v-for="(custodian, index) in custodians"
					:key="index"
					class="woo-search-plan__custodian">
					<NcTextField
						v-model="custodian.name"
						:data-testid="`woo-plan-custodian-name-${index}`"
						:label="t('dossiq', 'Person or function')" />
					<NcTextField
						v-model="custodian.function"
						:data-testid="`woo-plan-custodian-function-${index}`"
						:label="t('dossiq', 'Department (optional)')" />
					<NcButton
						:aria-label="t('dossiq', 'Remove')"
						variant="tertiary"
						@click="custodians.splice(index, 1)">
						{{ t('dossiq', 'Remove') }}
					</NcButton>
				</div>
				<NcButton
					data-testid="woo-plan-add-custodian"
					@click="custodians.push({ name: '', function: '' })">
					{{ t('dossiq', 'Add a person or function') }}
				</NcButton>
			</fieldset>

			<fieldset class="woo-search-plan__group" data-testid="woo-plan-systems">
				<legend>{{ t('dossiq', 'Systems') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="source in sources"
					:key="source.id"
					:modelValue="systems.includes(source.id)"
					:data-testid="`woo-plan-system-${source.id}`"
					@update:modelValue="toggle(source.id, $event)">
					{{ source.label }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<div class="woo-search-plan__period">
				<NcTextField
					v-model="periodFrom"
					type="date"
					data-testid="woo-plan-from"
					:label="t('dossiq', 'From')" />
				<NcTextField
					v-model="periodTo"
					type="date"
					data-testid="woo-plan-to"
					:label="t('dossiq', 'To')" />
			</div>

			<NcTextField
				v-model="terms"
				data-testid="woo-plan-terms"
				:label="t('dossiq', 'Search terms')" />

			<p
				v-if="error"
				class="woo-search-plan__error"
				data-testid="woo-plan-error"
				role="alert">
				{{ error }}
			</p>

			<section
				v-if="history.length > 0"
				class="woo-search-plan__history"
				data-testid="woo-plan-history">
				<h3>{{ t('dossiq', 'History') }}</h3>
				<ul>
					<li v-for="entry in history" :key="entry.id">
						{{ entry.when }} · {{ entry.who }} · {{ entry.what }}
					</li>
				</ul>
			</section>
		</div>

		<template #actions>
			<NcButton data-testid="woo-plan-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Close') }}
			</NcButton>
			<NcButton
				data-testid="woo-plan-save"
				variant="primary"
				:disabled="!complete || busy"
				@click="save">
				{{ t('dossiq', 'Record plan') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const PAGE_REFRESH = 'cn:page:refresh'
const DATE = /^\d{4}-\d{2}-\d{2}$/

export default {
	name: 'WooSearchPlanDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
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
			custodians: [{ name: '', function: '' }],
			systems: [],
			periodFrom: '',
			periodTo: '',
			terms: '',
			sources: [],
			plan: null,
			history: [],
			error: '',
			busy: false,
		}
	},

	computed: {
		/** @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001 */
		targetCaseId() {
			const given = this.caseId.startsWith('@') ? '' : this.caseId
			return given || String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001 */
		complete() {
			return (
				this.custodians.some((custodian) => custodian.name.trim() !== '')
				&& this.systems.length > 0
				&& DATE.test(this.periodFrom)
				&& DATE.test(this.periodTo)
				&& this.periodFrom <= this.periodTo
				&& this.terms.trim() !== ''
			)
		},

		/** @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001 */
		recordedLine() {
			if (!this.plan?.recordedAt) {
				return this.plan
					? t('dossiq', 'This plan is a draft. Complete it and record it.')
					: ''
			}
			return t('dossiq', 'Recorded by {who} on {when}', {
				who: this.plan.recordedBy,
				when: this.plan.recordedAt.slice(0, 10),
			})
		},
	},

	/** @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001 */
	async mounted() {
		await Promise.all([this.loadSources(), this.loadPlan()])
	},

	methods: {
		t,

		/**
		 * The systems a plan can name, as the case's source list names them.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
		 */
		async loadSources() {
			try {
				const { data } = await axios.get(this.url('/woo/sources'))
				this.sources = Array.isArray(data?.sources) ? data.sources : []
			} catch {
				this.sources = []
			}
		},

		/**
		 * The plan as it stands, and its history.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
		 */
		async loadPlan() {
			try {
				const { data } = await axios.get(this.url('/woo/plan'))
				this.fill(data?.plan ?? null)
			} catch (error) {
				this.error =
					error?.response?.data?.message
					|| t('dossiq', 'The search plan could not be read.')
			}
		},

		/**
		 * Put a plan into the form and read its history.
		 *
		 * @param {object|null} plan The plan.
		 * @return {void}
		 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
		 */
		fill(plan) {
			this.plan = plan
			if (!plan) {
				return
			}
			const custodians = Array.isArray(plan.custodians) ? plan.custodians : []
			this.custodians =
				custodians.length > 0
					? custodians.map((custodian) => ({
							name: custodian.name ?? '',
							function: custodian.function ?? '',
						}))
					: [{ name: '', function: '' }]
			this.systems = Array.isArray(plan.systems) ? [...plan.systems] : []
			this.periodFrom = plan.periodFrom ?? ''
			this.periodTo = plan.periodTo ?? ''
			this.terms = plan.terms ?? ''
			if (plan.id) {
				this.loadHistory(plan.id)
			}
		},

		/**
		 * The plan's OpenRegister audit trail: who changed it, when, and how.
		 *
		 * @param {string} planId The plan's uuid.
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
		 */
		async loadHistory(planId) {
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/dossiq/wooSearchPlan/${encodeURIComponent(planId)}/audit-trails`,
					),
				)
				const rows = Array.isArray(data) ? data : (data?.results ?? [])
				this.history = rows.map((row, index) => ({
					id: String(row.id ?? index),
					when: String(row.created ?? '')
						.slice(0, 16)
						.replace('T', ' '),
					who: row.userName || row.user || '',
					what:
						row.action === 'create'
							? t('dossiq', 'created')
							: t('dossiq', 'changed'),
				}))
			} catch {
				this.history = []
			}
		},

		/**
		 * A dossiq url under this case.
		 *
		 * @param {string} tail The path after the case.
		 * @return {string} The url.
		 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
		 */
		url(tail) {
			return generateUrl(
				`/apps/dossiq/api/cases/${encodeURIComponent(this.targetCaseId)}${tail}`,
			)
		},

		/**
		 * Tick or untick a system.
		 *
		 * @param {string} id The source id.
		 * @param {boolean} on Whether it is ticked.
		 * @return {void}
		 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
		 */
		toggle(id, on) {
			this.systems = on
				? [...new Set([...this.systems, id])]
				: this.systems.filter((system) => system !== id)
		},

		/**
		 * Record the plan.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
		 */
		async save() {
			if (!this.complete || this.busy) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.put(this.url('/woo/plan'), {
					custodians: this.custodians
						.filter((custodian) => custodian.name.trim() !== '')
						.map((custodian) => ({
							name: custodian.name.trim(),
							function: custodian.function.trim(),
						})),
					systems: this.systems,
					periodFrom: this.periodFrom,
					periodTo: this.periodTo,
					terms: this.terms.trim(),
				})
				this.fill(data?.plan ?? null)
				emit(PAGE_REFRESH, {})
			} catch (error) {
				this.error =
					error?.response?.data?.message
					|| t('dossiq', 'The search plan was not recorded.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.woo-search-plan {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 16px 16px;
}

.woo-search-plan__group {
	border: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.woo-search-plan__custodian,
.woo-search-plan__period {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}

.woo-search-plan__muted {
	color: var(--color-text-maxcontrast);
}

.woo-search-plan__error {
	color: var(--color-error-text, var(--color-error));
}

.woo-search-plan__history ul {
	list-style: none;
	margin: 0;
	padding: 0;
}
</style>
