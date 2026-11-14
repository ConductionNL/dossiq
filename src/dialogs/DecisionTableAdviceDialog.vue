<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Advice from the decision table a case type names, the case page header
	action (board DqHandhaving). The handler fills the inputs the table
	declares, the server asks the table and stores the recommendation on the
	case, and the handler accepts it or picks another outcome with a reason.
	Nothing here is named after a procedure: the inputs, the outcomes and the
	table come from the case type's configuration. Every verdict, including
	"a deviation needs a reason", is the server's; the dialog only says it.

	@spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
-->
<template>
	<CnDialog
		:name="t('dossiq', 'Advice from the decision table')"
		data-testid="decision-advice-dialog"
		@closing="$emit('close')">
		<p v-if="loading" class="decision-advice__note" data-testid="decision-advice-loading">
			{{ t('dossiq', 'Reading the decision table…') }}
		</p>

		<p
			v-else-if="form === null || form.available !== true"
			class="decision-advice__note"
			role="status"
			data-testid="decision-advice-unavailable">
			{{ t('dossiq', 'The case type of this case names no decision table for advice.') }}
		</p>

		<div v-else-if="advice === null" class="decision-advice">
			<NcSelect
				v-for="input in form.inputs"
				:key="input.name"
				:data-testid="`decision-advice-input-${input.name}`"
				:modelValue="values[input.name] || null"
				:inputLabel="input.label || input.name"
				:options="input.options"
				:taggable="input.options.length === 0"
				:clearable="false"
				@update:modelValue="setValue(input.name, $event)" />
		</div>

		<div v-else-if="decided === null" class="decision-advice">
			<p class="decision-advice__recommendation" data-testid="decision-advice-recommendation">
				{{ t('dossiq', 'The decision table recommends: {recommendation}', { recommendation: advice.recommendation }) }}
			</p>

			<NcSelect
				v-model="outcome"
				data-testid="decision-advice-outcome"
				:inputLabel="t('dossiq', 'Outcome')"
				:options="outcomes"
				:clearable="false"
				taggable />

			<NcTextArea
				v-if="deviates"
				v-model="reason"
				data-testid="decision-advice-reason"
				:label="t('dossiq', 'Reason for the deviation')"
				resize="vertical" />
		</div>

		<p
			v-else
			class="decision-advice__saved"
			role="status"
			data-testid="decision-advice-saved">
			{{ savedSentence }}
		</p>

		<p
			v-if="error"
			class="decision-advice__error"
			role="alert"
			data-testid="decision-advice-error">
			{{ error }}
		</p>

		<template #actions>
			<template v-if="decided === null && form !== null && form.available === true">
				<NcButton
					data-testid="decision-advice-cancel"
					@click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
				<NcButton
					v-if="advice === null"
					variant="primary"
					data-testid="decision-advice-ask"
					:disabled="!canAsk"
					@click="ask">
					{{ t('dossiq', 'Ask for advice') }}
				</NcButton>
				<NcButton
					v-else
					variant="primary"
					data-testid="decision-advice-save"
					:disabled="busy || outcome === ''"
					@click="save">
					{{ deviates ? t('dossiq', 'Record the deviation') : t('dossiq', 'Accept the advice') }}
				</NcButton>
			</template>
			<NcButton
				v-else
				variant="primary"
				data-testid="decision-advice-close"
				@click="$emit('close')">
				{{ t('dossiq', 'Close') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import { adviceForm, adviceRefusal, decide, recommend } from '../services/decisionAdviceApi.js'

export default {
	name: 'DecisionTableAdviceDialog',

	components: {
		CnDialog,
		NcButton,
		NcSelect,
		NcTextArea,
	},

	props: {
		/**
		 * The case. Arrives as the unresolved `@objectId` token from an
		 * `open-modal` action, so the route answers instead.
		 */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			form: null,
			values: {},
			advice: null,
			outcome: '',
			reason: '',
			decided: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md */
		targetCaseId() {
			const given = String(this.caseId || '')
			if (given !== '' && given.startsWith('@') === false) {
				return given
			}

			return String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md */
		canAsk() {
			return this.busy === false
				&& (this.form?.inputs || []).every((input) => String(this.values[input.name] ?? '').trim() !== '')
		},

		/** @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md */
		outcomes() {
			const recommended = String(this.advice?.recommendation ?? '')
			const listed = (this.form?.outcomes || []).filter((value) => value !== recommended)
			return recommended === '' ? listed : [recommended, ...listed]
		},

		/** @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md */
		deviates() {
			return this.advice !== null
				&& this.outcome !== ''
				&& this.outcome !== String(this.advice.recommendation ?? '')
		},

		/** @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md */
		savedSentence() {
			if (this.decided?.accepted === true) {
				return t('dossiq', 'The advice is accepted and recorded on this case.')
			}

			return t('dossiq', 'The deviation is recorded on this case: {outcome}.', { outcome: this.decided?.outcome ?? '' })
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read what the case type's decision table asks.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const form = await adviceForm(this.targetCaseId)
				this.form = {
					...form,
					inputs: (form?.inputs || []).map((input) => ({ ...input, options: Array.isArray(input?.options) ? input.options : [] })),
				}
			} catch (error) {
				this.form = null
				this.error = adviceRefusal(error, t('dossiq', 'The decision table could not be read.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Keep the value picked for one input.
		 *
		 * @param {string} name The input.
		 * @param {string} value What was picked.
		 * @return {void}
		 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
		 */
		setValue(name, value) {
			this.values = { ...this.values, [name]: value ?? '' }
		},

		/**
		 * Ask the table for a recommendation; the server stores it on the case.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
		 */
		async ask() {
			if (!this.canAsk) {
				return
			}

			this.busy = true
			this.error = ''
			try {
				this.advice = await recommend(this.targetCaseId, { ...this.values })
				this.outcome = String(this.advice?.recommendation ?? '')
			} catch (error) {
				this.error = adviceRefusal(error, t('dossiq', 'The decision table gave no advice.'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Record the choice: the recommendation, or another outcome with a reason.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
		 */
		async save() {
			if (this.advice === null || this.outcome === '') {
				return
			}

			this.busy = true
			this.error = ''
			try {
				this.decided = await decide(String(this.advice.id ?? ''), this.outcome, this.deviates ? this.reason : '')
			} catch (error) {
				this.error = adviceRefusal(error, t('dossiq', 'The choice could not be recorded.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.decision-advice {
	display: flex;
	flex-direction: column;
	gap: 12px;

	&__note,
	&__recommendation {
		margin: 0;
	}

	&__recommendation {
		font-weight: bold;
	}

	&__error {
		margin: 12px 0 0;
		color: var(--color-error-text, var(--color-error));
	}

	&__saved {
		margin: 0;
		padding: 12px 14px;
		border-radius: var(--border-radius-large);
		background: var(--color-success);
		color: var(--color-success-text, var(--color-primary-element-text));
	}
}
</style>
