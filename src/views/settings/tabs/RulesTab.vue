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

  @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
-->
<template>
	<div class="rules-tab">
		<h4>{{ t('dossiq', 'Rules on the case') }}</h4>
		<p class="rules-tab__hint">
			{{
				t(
					'dossiq',
					'Everything the platform checks when a case is saved, in the order it checks it. Statuses and fields are declared on the other tabs; this is what came of them.',
				)
			}}
		</p>

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

		<table v-else class="rules-tab__table" data-testid="rules-table">
			<thead>
				<tr>
					<th scope="col">{{ t('dossiq', 'Rule') }}</th>
					<th scope="col">{{ t('dossiq', 'Kind') }}</th>
					<th scope="col">{{ t('dossiq', 'On') }}</th>
					<th scope="col">{{ t('dossiq', 'Last run') }}</th>
					<th scope="col"><span class="hidden-visually">{{ t('dossiq', 'Try') }}</span></th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="rule in rules" :key="rule.id" data-testid="rules-row">
					<th scope="row">{{ rule.label || rule.key || rule.id }}</th>
					<td data-testid="rules-kind">{{ kindLabel(rule) }}</td>
					<td>{{ rule.enabled === false ? t('dossiq', 'Off') : t('dossiq', 'On') }}</td>
					<td>{{ rule.lastRun || t('dossiq', 'Never') }}</td>
					<td>
						<NcButton
							variant="tertiary"
							:disabled="trying === rule.id || caseId === ''"
							:data-testid="`rules-try-${rule.id}`"
							@click="tryRule(rule)">
							{{ t('dossiq', 'Try it') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<div class="rules-tab__trial">
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
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import {
	evaluateRule,
	fetchRuleVocabulary,
	fetchSchemaRules,
	kindsByName,
	traceOf,
} from '../../../services/schemaRules.js'
import { useSettingsStore } from '../../../store/modules/settings.js'

export default {
	name: 'RulesTab',

	components: {
		NcButton,
		NcLoadingIcon,
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
			caseId: '',
			trying: '',
			trace: null,
		}
	},

	computed: {
		/**
		 * What the trial decided, as a sentence.
		 *
		 * @return {string} The line.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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

	/** @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md */
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
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		async load() {
			this.loading = true
			this.error = ''
			this.kinds = kindsByName(await fetchRuleVocabulary())

			try {
				this.rules = await fetchSchemaRules(this.schemaSlug())
			} catch (e) {
				this.rules = []
				this.error =
					Number(e?.response?.status) === 403
						? t('dossiq', 'Only an administrator can read the rules of a case.')
						: t('dossiq', 'The rules could not be read. Ask an administrator.')
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
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		schemaSlug() {
			return String(useSettingsStore().config?.case_schema || 'case')
		},

		/**
		 * The register the case lives in.
		 *
		 * @return {string} The slug.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		registerSlug() {
			return String(useSettingsStore().config?.register || 'dossiq')
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
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
.rules-tab__hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 4px 0 12px;
}

.rules-tab__table {
	width: 100%;
	border-collapse: collapse;
}

.rules-tab__table th,
.rules-tab__table td {
	text-align: start;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.rules-tab__trial {
	margin-top: 16px;
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
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
	color: var(--color-error);
}

.rules-tab__empty {
	color: var(--color-text-maxcontrast);
}
</style>
