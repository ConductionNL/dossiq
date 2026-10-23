<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The rules the platform holds for the case, listed and tried.

  Not a rule screen of its own. OpenRegister's engine evaluates four kinds in a
  fixed order on every save, and this tab reads its inventory and its dry run
  (ADR-022: one engine, a leaf declares). Building a second screen here would
  mean a second answer to "why did this rule not fire", and the two disagree
  within a week.

  The kind of a rule is labelled from the engine's own vocabulary rather than
  from a list in this file, so a kind added after this release renders instead
  of blanking. The DMN decision tables keep working exactly as they do: they are
  the `flow` kind's neighbour, not something this replaces.

  @spec openspec/specs/status-transition-engine/spec.md
-->
<template>
	<div class="rules-tab">
		<NcLoadingIcon v-if="loading" :size="32" />

		<p v-else-if="error" class="rules-tab__error" role="alert">
			{{ error }}
		</p>

		<p v-else-if="rules.length === 0" class="rules-tab__empty">
			{{
				t(
					'dossiq',
					'Nothing is checked on the case yet. Give a status a field rule and publish the case type.',
				)
			}}
		</p>

		<ul v-else class="rules-tab__rows" data-testid="rules-list">
			<li v-for="row in rows" :key="row.id" class="rules-tab__row" data-testid="rules-row">
				<span class="rules-tab__text">
					<span class="rules-tab__title">{{ row.title }}</span>
					<span v-if="row.line" class="rules-tab__line">{{ row.line }}</span>
				</span>
				<span
					class="rules-tab__pill cn-status-badge"
					:class="row.pill === 'on' ? 'cn-status-badge--success' : 'cn-status-badge--default'"
					data-testid="rules-pill">{{ pillLabel(row.pill) }}</span>
			</li>
		</ul>

		<details v-if="rules.length > 0" class="rules-tab__trial">
			<summary>{{ t('dossiq', 'Try a rule against a case') }}</summary>
			<NcSelect
				v-model="picked"
				:options="rows"
				label="title"
				:inputLabel="t('dossiq', 'Rule')"
				data-testid="rules-pick" />
			<NcTextField
				:modelValue="caseId"
				:label="t('dossiq', 'Case to try a rule against')"
				:placeholder="t('dossiq', 'Paste a case id')"
				data-testid="rules-case-id"
				@update:modelValue="(v) => (caseId = v)" />
			<p class="rules-tab__hint">
				{{
					t(
						'dossiq',
						'A trial writes nothing. It reads the case as it stands and says what the rule would have decided.',
					)
				}}
			</p>
			<NcButton
				:disabled="!picked || trying !== '' || caseId === ''"
				data-testid="rules-try"
				@click="tryRule(picked)">
				{{ t('dossiq', 'Try it') }}
			</NcButton>

			<div v-if="trace" class="rules-tab__trace" data-testid="rules-trace">
				<p class="rules-tab__verdict">
					{{ verdictLine }}
				</p>
				<p v-if="trace.operand" data-testid="rules-operand">
					{{
						t('dossiq', 'It read {operand} and found {value}.', {
							operand: trace.operand,
							value: trace.operandValue || t('dossiq', 'nothing'),
						})
					}}
				</p>
				<p v-if="trace.message">{{ trace.message }}</p>
			</div>
		</details>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	evaluateRule,
	fetchRuleVocabulary,
	fetchSchemaProperties,
	fetchSchemaRules,
	kindsByName,
	ruleRows,
	traceOf,
} from '../../../services/schemaRules.js'
import { useSettingsStore } from '../../../store/modules/settings.js'

export default {
	name: 'RulesTab',

	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},

	props: {
		/**
		 * Whether the page is creating a case type rather than editing one.
		 *
		 * The tab takes no case type, deliberately. Rules live on the SCHEMA,
		 * and every case type on the instance projects onto the same one, so a
		 * per-case-type list would have to guess which rules belong to which
		 * and would be wrong the first time two types locked the same field.
		 * The inventory is the whole truth about what the case is checked
		 * against.
		 */
		isCreate: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			loading: false,
			error: '',
			rules: [],
			kinds: {},
			properties: {},
			picked: null,
			caseId: '',
			trying: '',
			trace: null,
		}
	},

	computed: {
		/**
		 * The rules as rows, titled and described in the reader's language.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/case-type-rules-as-rows/specs/case-types/spec.md#requirement-the-rules-tab-lists-rules-as-rows
		 */
		rows() {
			return ruleRows(this.rules, this.properties, this.kinds).map((row) => ({
				...row,
				title: row.title ? t('dossiq', row.title) : row.id,
				line: row.line ? t('dossiq', row.line) : '',
			}))
		},

		/**
		 * What the trial decided, as a sentence.
		 *
		 * @return {string} The line.
		 *
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		verdictLine() {
			const verdicts = {
				fired: t('dossiq', 'The rule fired.'),
				no_match: t('dossiq', 'The rule did not match this case.'),
				refused: t('dossiq', 'The rule refused the save.'),
				error: t('dossiq', 'The rule could not be evaluated.'),
			}

			return verdicts[this.trace?.verdict] || this.trace?.verdict || ''
		},
	},

	/** @spec openspec/specs/status-transition-engine/spec.md */
	async mounted() {
		if (this.isCreate === false) {
			await this.load()
		}
	},

	methods: {
		/**
		 * The reader's word for a rule's kind.
		 *
		 * Falls back to the kind the rule itself carries, which is how a kind
		 * this release predates still renders something. A blank cell would
		 * read as "this rule has no kind", which is never true.
		 *
		 * @param {object} rule One inventory row.
		 * @return {string} The label.
		 *
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		kindLabel(rule) {
			const kind = String(rule?.kind ?? '')

			return String(this.kinds[kind]?.description || kind)
		},

		/**
		 * Read the vocabulary and the inventory.
		 *
		 * The inventory is an admin read, so a handler who opened this page is
		 * refused and told so in one sentence rather than shown an empty table
		 * that reads as "this case checks nothing".
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		async load() {
			this.loading = true
			this.error = ''
			this.kinds = kindsByName(await fetchRuleVocabulary())
			this.properties = await fetchSchemaProperties(this.schemaSlug())

			try {
				this.rules = await fetchSchemaRules(this.schemaSlug())
			} catch (e) {
				this.rules = []
				this.error =
					Number(e?.response?.status) === 403
						? t(
								'dossiq',
								'Only an administrator can read the rules of a case.',
							)
						: t(
								'dossiq',
								'The rules could not be read. Ask an administrator.',
							)
			}

			this.loading = false
		},

		/**
		 * The schema the case lives in.
		 *
		 * Read from the settings rather than written here, because an instance
		 * may have imported the register under a slug of its own and a literal
		 * would silently address another app's schema.
		 *
		 * @return {string} The slug.
		 *
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		schemaSlug() {
			return String(useSettingsStore().config?.case_schema || 'case')
		},

		/**
		 * The register the case lives in.
		 *
		 * @return {string} The slug.
		 *
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		registerSlug() {
			return String(useSettingsStore().config?.register || 'dossiq')
		},

		/**
		 * The pill text of a row.
		 *
		 * @param {string} pill calculated, on or off.
		 * @return {string} The text.
		 */
		pillLabel(pill) {
			return { calculated: t('dossiq', 'Calculated'), on: t('dossiq', 'On'), off: t('dossiq', 'Off') }[pill] || ''
		},

		/**
		 * Try one rule against the case the administrator named.
		 *
		 * Keyed on the rule's id and never on its row, because the order is a
		 * property of the pipeline and moves the day a kind is added.
		 *
		 * @param {object} rule One inventory row.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		async tryRule(rule) {
			const ruleId = String(rule?.id ?? '')
			if (ruleId === '' || this.caseId.trim() === '') {
				return
			}

			this.trying = ruleId
			this.trace = null

			try {
				const result = await evaluateRule(
					this.schemaSlug(),
					ruleId,
					this.registerSlug(),
					this.caseId.trim(),
				)
				this.trace = traceOf(result)
			} catch {
				this.trace = {
					verdict: 'error',
					operand: '',
					operandValue: '',
					message: t('dossiq', 'That case could not be read.'),
				}
			}

			this.trying = ''
		},
	},
}
</script>

<style scoped>
.rules-tab__rows {
	margin: 0;
	padding: 0;
	list-style: none;
}

.rules-tab__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 10px 0;
	border-top: 1px solid var(--color-border);
}

.rules-tab__text {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.rules-tab__title {
	font-size: 14px;
	font-weight: 600;
	overflow-wrap: anywhere;
}

.rules-tab__line {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.rules-tab__pill {
	flex: none;
}

.rules-tab__hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 4px 0 12px;
}

.rules-tab__trial {
	margin-top: 16px;
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
}

.rules-tab__trial > summary {
	cursor: pointer;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.rules-tab__trace {
	margin-top: 8px;
	padding: 8px 12px;
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-background-hover);
}

.rules-tab__trace p {
	margin: 0;
}

.rules-tab__verdict {
	font-weight: bold;
}

.rules-tab__error {
	color: var(--color-error-text);
}

.rules-tab__empty {
	color: var(--color-text-maxcontrast);
}
</style>
