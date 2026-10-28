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

		<div v-if="!loading && caseTypeId && !summary" class="rules-tab__head">
			<NcButton
				:disabled="!editable"
				:title="editable ? '' : t('dossiq', 'A published version does not change. Make a new version to change its rules.')"
				data-testid="rules-add"
				@click="dialog = { rule: null }">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('dossiq', 'Add rule') }}
			</NcButton>
		</div>

		<ul v-if="!loading && caseRuleRows.length > 0" class="rules-tab__rows" data-testid="case-rules-list">
			<li v-for="(row, i) in caseRuleRows" :key="row.id" class="rules-tab__row" data-testid="case-rule-row">
				<span class="rules-tab__text">
					<span class="rules-tab__title">{{ row.title }}</span>
					<span class="rules-tab__line">{{ row.line }}</span>
				</span>
				<span class="rules-tab__end">
					<span
						class="rules-tab__pill cn-status-badge"
						:class="row.pill === 'on' ? 'cn-status-badge--success' : 'cn-status-badge--default'"
						data-testid="case-rule-pill">{{ pillLabel(row.pill) }}</span>
					<NcActions v-if="editable && !summary" :aria-label="t('dossiq', 'Actions for {rule}', { rule: row.title })">
						<NcActionButton data-testid="case-rule-edit" @click="dialog = { rule: caseRules[i] }">
							{{ t('dossiq', 'Edit') }}
						</NcActionButton>
						<NcActionButton data-testid="case-rule-toggle" @click="toggleRule(i)">
							{{ row.pill === 'on' ? t('dossiq', 'Turn off') : t('dossiq', 'Turn on') }}
						</NcActionButton>
						<NcActionButton data-testid="case-rule-delete" @click="deleteRule(i)">
							{{ t('dossiq', 'Delete') }}
						</NcActionButton>
					</NcActions>
				</span>
			</li>
		</ul>

		<p
			v-if="summary && !loading && caseRuleRows.length === 0"
			class="rules-tab__empty"
			data-testid="case-rules-empty">
			{{ t('dossiq', 'No rules for cases yet') }}
		</p>

		<p v-if="!summary && !loading && !error && rules.length === 0 && caseRuleRows.length === 0" class="rules-tab__empty">
			{{
				t(
					'dossiq',
					'Nothing is checked on the case yet. Give a status a field rule and publish the case type.',
				)
			}}
		</p>

		<ul v-if="!summary && !loading && !error && rows.length > 0" class="rules-tab__rows" data-testid="rules-list">
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

		<CaseTypeRuleDialog
			v-if="dialog"
			:rule="dialog.rule"
			:newId="newId"
			:fields="fields"
			:choices="choices"
			@close="dialog = null"
			@save="saveRule" />

		<details v-if="!summary && rules.length > 0" class="rules-tab__trial">
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
import { NcActionButton, NcActions, NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import CaseTypeRuleDialog from '../../../modals/CaseTypeRuleDialog.vue'
import { caseTypeRuleRow, fetchBlueprintCatalogue, newRuleId, ruleFields } from '../../../services/caseTypeRules.js'
import {
	evaluateRule,
	fetchRuleVocabulary,
	fetchSchemaProperties,
	fetchSchemaRules,
	kindsByName,
	ruleRows,
	traceOf,
} from '../../../services/schemaRules.js'
import { useObjectStore } from '../../../store/modules/object.js'
import { useSettingsStore } from '../../../store/modules/settings.js'
import { resolveTranslatable } from '../../../utils/i18nResolver.js'

export default {
	name: 'RulesTab',

	components: {
		CaseTypeRuleDialog,
		NcActionButton,
		NcActions,
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		Plus,
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

		/**
		 * The case type whose own conditional rules lead the tab, and whose
		 * scope the inventory is asked for. Empty shows the schema inventory only.
		 */
		caseTypeId: {
			type: String,
			default: '',
		},

		/**
		 * Only the case type's own rules, without menus, the Add button, the
		 * inventory or the trial: the Overview tab's Regels card
		 * (DqZaaktypeOverzicht).
		 */
		summary: {
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
			caseType: null,
			catalogue: { propertyDefinitions: [], statusTypes: [], documentTypes: [], emailTemplates: [], roleTypes: [] },
			dialog: null,
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
			// A flow scoped to this case type is one of its own rules, projected; the rule row stands for it.
			const inventory = this.rules.filter((r) => !(r?.kind === 'flow' && r?.scope?.property === 'caseType'))
			return ruleRows(inventory, this.properties, this.kinds).map((row) => ({
				...row,
				title: row.title ? t('dossiq', row.title) : row.id,
				line: row.line ? t('dossiq', row.line) : '',
			}))
		},

		/**
		 * The case type's own rules, in their order.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
		 */
		caseRules() {
			return Array.isArray(this.caseType?.rules) ? this.caseType.rules : []
		},

		/** @return {Array<object>} The fields a condition can name. */
		fields() {
			return ruleFields(this.catalogue)
		},

		/** @return {object} The choices the rule dialog offers, as `[{ id, label }]` lists. */
		choices() {
			// A name or title can be a language map; the label is its text in the reader's language.
			const options = (list, name = 'name') => list.map((o) => ({ id: o.id, label: resolveTranslatable(o[name] || o.title || o.id).text }))
			return {
				statuses: options(this.catalogue.statusTypes),
				documentTypes: options(this.catalogue.documentTypes),
				templates: options(this.catalogue.emailTemplates),
				roles: options(this.catalogue.roleTypes || []),
				variants: (this.caseType?.variants || []).map((v) => ({ id: v.name, label: v.name })),
			}
		},

		/**
		 * The case type's rules as "If … / then …" rows.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
		 */
		caseRuleRows() {
			const byId = (list) => Object.fromEntries(list.map((o) => [o.id, o.label]))
			const names = {
				statuses: byId(this.choices.statuses),
				documentTypes: byId(this.choices.documentTypes),
				templates: byId(this.choices.templates),
				fields: byId(this.fields),
			}
			return this.caseRules.map((rule) => caseTypeRuleRow(rule, this.fields, names))
		},

		/** @return {string} The id a new rule gets. */
		newId() {
			return newRuleId(this.caseRules)
		},

		/**
		 * Whether this version's rules may change: a published version is frozen.
		 *
		 * @return {boolean}
		 */
		editable() {
			return this.caseType !== null && this.caseType.isDraft !== false
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
		 * The reads run side by side: one after the other they took about 17
		 * seconds on :8080, longer than anyone waits for a spinner.
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
			if (this.summary) {
				await this.loadCaseType()
				this.loading = false
				return
			}
			const [vocabulary, properties, rules] = await Promise.all([
				fetchRuleVocabulary(),
				fetchSchemaProperties(this.schemaSlug()),
				fetchSchemaRules(this.schemaSlug(), this.caseTypeId ? { caseType: this.caseTypeId } : {}).then((list) => ({ list }), (e) => ({ e })),
				this.loadCaseType(),
			])
			this.kinds = kindsByName(vocabulary)
			this.properties = properties

			try {
				if (rules.e) {
					throw rules.e
				}
				this.rules = rules.list
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
		 * Read the case type and what its rules can point at.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
		 */
		async loadCaseType() {
			if (!this.caseTypeId) {
				return
			}
			const store = useObjectStore()
			const list = async (type) => (await store.fetchCollection(type, { caseType: this.caseTypeId, _limit: 200 }).catch(() => [])) || []
			const [caseType, blueprint, documentTypes, emailTemplates, roleTypes] = await Promise.all([
				store.fetchObject('caseType', this.caseTypeId).catch(() => null),
				fetchBlueprintCatalogue(this.caseTypeId),
				list('documentType'),
				list('emailTemplate'),
				list('roleType'),
			])
			this.caseType = caseType || null
			const [propertyDefinitions, statusTypes] = blueprint
				? [blueprint.propertyDefinitions, blueprint.statusTypes]
				: await Promise.all([list('propertyDefinition'), list('statusType')])
			this.catalogue = { propertyDefinitions, statusTypes, documentTypes, emailTemplates, roleTypes }
		},

		/**
		 * Store the case type with these rules.
		 *
		 * @param {Array<object>} rules The rules.
		 * @return {Promise<void>}
		 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
		 */
		async storeRules(rules) {
			const next = { ...this.caseType, rules }
			try {
				const saved = await useObjectStore().saveObject('caseType', next)
				this.caseType = saved && typeof saved === 'object' ? { ...next, ...saved } : next
			} catch {
				this.error = t('dossiq', 'The rule could not be saved.')
			}
		},

		/**
		 * Add or replace a rule from the dialog.
		 *
		 * @param {object} rule The rule.
		 * @return {Promise<void>}
		 */
		async saveRule(rule) {
			const rules = [...this.caseRules]
			const at = rules.findIndex((r) => r.id === rule.id)
			if (at === -1) {
				rules.push(rule)
			} else {
				rules.splice(at, 1, rule)
			}
			this.dialog = null
			await this.storeRules(rules)
		},

		/**
		 * Turn a rule off or on.
		 *
		 * @param {number} i The rule's position.
		 * @return {Promise<void>}
		 */
		async toggleRule(i) {
			const rules = this.caseRules.map((r, j) => (j === i ? { ...r, enabled: r.enabled === false } : r))
			await this.storeRules(rules)
		},

		/**
		 * Delete a rule.
		 *
		 * @param {number} i The rule's position.
		 * @return {Promise<void>}
		 */
		async deleteRule(i) {
			await this.storeRules(this.caseRules.filter((r, j) => j !== i))
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
	gap: 2px;
	min-width: 0;
}

.rules-tab__title {
	font-size: 15px;
	font-weight: 500;
	line-height: normal;
	overflow-wrap: anywhere;
}

.rules-tab__line {
	font-size: 13px;
	line-height: normal;
	color: var(--color-text-maxcontrast);
}

.rules-tab__pill {
	flex: none;
}

/* The board wraps the add button into the tab's heading row, 8px under the
   subtitle where the panel heading leaves 12px, and 12px above the rows. */
.rules-tab__head {
	display: flex;
	justify-content: flex-start;
	margin: -4px 0 12px;
}

.rules-tab__end {
	display: flex;
	align-items: center;
	gap: 4px;
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
	line-height: normal;
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

<style>
/* A board card that opens on the rule rows starts 12px under its title: the
   rows set their own padding, so no first line carries the half-leading the
   card content takes off for text (r31 DqZaaktypeOverzicht Regels sat 2.3px
   high). */
.cn-look-board .cn-detail-page .cn-widget-wrapper__content:has(> .rules-tab > .rules-tab__rows:first-child):not(.cn-look-nextcloud *) {
	padding-top: 12px;
}
</style>
