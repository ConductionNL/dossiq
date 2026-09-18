<template>
	<div
		v-if="offered.length"
		class="case-type-field-filters"
		data-testid="case-type-field-filters">
		<div class="case-type-field-filters__row">
			<template v-for="definition in offered" :key="idOf(definition)">
				<label
					v-if="controlOf(definition) === 'select'"
					class="case-type-field-filters__field">
					<span class="case-type-field-filters__label">{{ definition.name }}</span>
					<select
						:data-testid="`field-filter-${idOf(definition)}`"
						:value="valueOf(definition).eq || ''"
						@change="setValue(definition, { eq: $event.target.value })">
						<option value="">{{ anyLabel }}</option>
						<option
							v-for="option in definition.enumValues"
							:key="option"
							:value="option">
							{{ option }}
						</option>
					</select>
				</label>

				<fieldset
					v-else-if="controlOf(definition) === 'range' || controlOf(definition) === 'dateRange'"
					class="case-type-field-filters__field">
					<legend class="case-type-field-filters__label">{{ definition.name }}</legend>
					<input
						:type="controlOf(definition) === 'range' ? 'number' : 'date'"
						:aria-label="fromLabel(definition)"
						:data-testid="`field-filter-${idOf(definition)}-from`"
						:value="valueOf(definition).gte || ''"
						@input="setValue(definition, { ...valueOf(definition), gte: $event.target.value })">
					<input
						:type="controlOf(definition) === 'range' ? 'number' : 'date'"
						:aria-label="toLabel(definition)"
						:data-testid="`field-filter-${idOf(definition)}-to`"
						:value="valueOf(definition).lte || ''"
						@input="setValue(definition, { ...valueOf(definition), lte: $event.target.value })">
				</fieldset>

				<label v-else class="case-type-field-filters__field">
					<span class="case-type-field-filters__label">{{ definition.name }}</span>
					<input
						type="text"
						:data-testid="`field-filter-${idOf(definition)}`"
						:value="valueOf(definition).eq || ''"
						@input="setValue(definition, { eq: $event.target.value })">
				</label>
			</template>
		</div>

		<NcNoteCard
			v-if="refusal"
			type="warning"
			class="case-type-field-filters__refusal"
			data-testid="case-type-field-filters-refusal">
			<p>{{ refusalHeadline }}</p>
			<p class="case-type-field-filters__refusal-reason">{{ refusal.message }}</p>
		</NcNoteCard>
	</div>
</template>

<script>
/**
 * The filters a case type's own fields offer, on the Cases page.
 *
 * 🔴 IT RENDERS NOTHING UNTIL A CASE TYPE IS PICKED, and that is a decision
 * rather than a limitation. The fields belong to one case type, so a bar
 * offering every case type's fields at once offers a list nobody can read and
 * a query that matches nothing: two definitions from two case types can never
 * both be satisfied by one case. Clearing the case type clears the field
 * filters with it, because a filter on a field the visible cases do not have
 * is a filter that empties the list and says nothing.
 *
 * 🔴 A REFUSED FILTER IS NOT AN EMPTY RESULT. openregister refuses a malformed
 * `_related` block with a sentence rather than dropping it, which is the right
 * behaviour: a dropped filter answers every case in the register, presented as
 * the answer to a narrow question. But a refusal recorded on the object store
 * still leaves `CnIndexPage` rendering its empty state, so the reader sees "no
 * cases match" for a filter that was never run. The refusal is rendered HERE,
 * under the bar, naming the field rather than the raw block.
 *
 * 🔑 IT SITS IN `after-search` AND NOT IN `below-header`. The change's proposal
 * named `after-search` for the refusal; `CaseSearchRefusal` already occupies
 * `below-header` and a slot holds one component. `after-search` is the actions
 * bar's slot for inline refinement controls, which is what a filter bar is, so
 * the bar goes there and carries its own refusal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
 */

import { useObjectStore } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import {
	buildRelatedFilters,
	controlFor,
	definitionId,
	filterableDefinitions,
	readRelatedRefusal,
	relatedKeysIn,
} from '../../utils/caseTypeFieldFilters.js'

/** The object store key CnIndexPage self-fetch mode uses for the Cases page. */
const CASE_STORE_KEY = 'dossiq-case'

export default {
	name: 'CaseTypeFieldFilters',

	components: { NcNoteCard },

	data() {
		return {
			/** The definitions of the case type currently picked. */
			definitions: [],
			/** What the handler has entered, by definition id. */
			values: {},
		}
	},

	computed: {
		/**
		 * The case type the folder sidebar has narrowed to, or an empty string.
		 *
		 * @return {string} The case type id.
		 */
		caseType() {
			const picked = this.$route?.query?.caseType

			return typeof picked === 'string' ? picked : ''
		},

		/**
		 * The definitions this case type offers as filters.
		 *
		 * @return {Array<object>} The definitions.
		 */
		offered() {
			return filterableDefinitions(this.definitions)
		},

		/**
		 * The shared object store, where the list's own failure is recorded.
		 *
		 * @return {object} The store.
		 */
		objectStore() {
			return useObjectStore()
		},

		/**
		 * The refusal to show, or null when the last fetch was not refused.
		 *
		 * @return {object|null} The refusal.
		 */
		refusal() {
			return readRelatedRefusal({
				error: this.objectStore?.errors?.[CASE_STORE_KEY] ?? null,
				definitions: this.definitions,
			})
		},

		/**
		 * The sentence above the refusal.
		 *
		 * @return {string} The headline.
		 */
		refusalHeadline() {
			if (this.refusal?.name) {
				return t('dossiq', 'The filter on {field} was refused, so this list is not an answer.', {
					field: this.refusal.name,
				})
			}

			return t('dossiq', 'One of the field filters was refused, so this list is not an answer.')
		},

		/** The option that asks for nothing. */
		anyLabel() {
			return t('dossiq', 'Any')
		},
	},

	watch: {
		caseType: {
			immediate: true,

			/**
			 * Load the picked case type's definitions, and clear what the
			 * handler entered for the one before it.
			 *
			 * @param {string} picked The case type id.
			 * @return {Promise<void>}
			 */
			async handler(picked) {
				this.values = {}
				if (picked === '') {
					this.definitions = []
					this.clearRelated()

					return
				}

				this.definitions = await this.loadDefinitions(picked)
			},
		},
	},

	methods: {
		/**
		 * The id a definition is addressed by.
		 *
		 * @param {object} definition The definition.
		 * @return {string} The id.
		 */
		idOf(definition) {
			return definitionId(definition)
		},

		/**
		 * The control a definition is asked for with.
		 *
		 * @param {object} definition The definition.
		 * @return {string} The control.
		 */
		controlOf(definition) {
			return controlFor(definition)
		},

		/**
		 * The accessible name of the lower bound of a range.
		 *
		 * A bare pair of boxes under one legend is two unlabelled inputs to a
		 * screen reader, which is the one reader who cannot see which is which.
		 *
		 * @param {object} definition The definition.
		 * @return {string} The label.
		 */
		fromLabel(definition) {
			return t('dossiq', '{field}, from', { field: definition?.name || '' })
		},

		/**
		 * The accessible name of the upper bound of a range.
		 *
		 * @param {object} definition The definition.
		 * @return {string} The label.
		 */
		toLabel(definition) {
			return t('dossiq', '{field}, up to', { field: definition?.name || '' })
		},

		/**
		 * What the handler has entered for one definition.
		 *
		 * @param {object} definition The definition.
		 * @return {object} The value.
		 */
		valueOf(definition) {
			return this.values[this.idOf(definition)] || {}
		},

		/**
		 * Record a value and push the compiled filters onto the route.
		 *
		 * @param {object} definition The definition.
		 * @param {object} value      The entered value.
		 * @return {void}
		 */
		setValue(definition, value) {
			this.values = { ...this.values, [this.idOf(definition)]: value }
			this.applyFilters()
		},

		/**
		 * The case type's property definitions.
		 *
		 * The filter key is BARE. OpenRegister's objects search reads
		 * `caseType` as a filter and would read `filter[caseType]` as the empty
		 * set, which presents as a case type with no fields rather than as an
		 * error.
		 *
		 * @param {string} caseType The case type id.
		 * @return {Promise<Array<object>>} The definitions.
		 */
		async loadDefinitions(caseType) {
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/{register}/{schema}',
					{ register: 'dossiq', schema: 'propertyDefinition' },
				)
				const response = await axios.get(url, {
					params: { caseType, _limit: 200 },
				})
				const body = response?.data?.results ?? response?.data ?? []

				return Array.isArray(body) ? body : []
			} catch {
				// A case type whose definitions cannot be read offers no
				// filters, which is the same as one that declares none. It is
				// NOT an empty bar over a filtered list: nothing was applied.
				return []
			}
		},

		/**
		 * Put the compiled `_related` blocks on the route, replacing whatever
		 * was there.
		 *
		 * @return {void}
		 */
		applyFilters() {
			const entries = this.offered.map((definition) => ({
				definitionId: this.idOf(definition),
				value: this.valueOf(definition),
			}))

			this.pushQuery(buildRelatedFilters(entries))
		},

		/**
		 * Take every `_related` key off the route.
		 *
		 * @return {void}
		 */
		clearRelated() {
			this.pushQuery({})
		},

		/**
		 * Replace the route's `_related` keys with the ones given.
		 *
		 * @param {object} related The keys to set.
		 * @return {void}
		 */
		pushQuery(related) {
			const current = { ...(this.$route?.query || {}) }
			relatedKeysIn(current).forEach((key) => {
				delete current[key]
			})

			const next = { ...current, ...related }
			if (JSON.stringify(next) === JSON.stringify(this.$route?.query || {})) {
				return
			}

			this.$router?.replace({ query: next })
		},
	},
}
</script>

<style scoped>
.case-type-field-filters {
	inline-size: 100%;
}

.case-type-field-filters__row {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
}

.case-type-field-filters__field {
	display: flex;
	flex-direction: column;
	gap: 2px;
	border: none;
	margin: 0;
	padding: 0;
}

.case-type-field-filters__label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.case-type-field-filters__refusal {
	margin-block-start: 8px;
}

.case-type-field-filters__refusal-reason {
	font-family: var(--font-face-monospace, monospace);
	overflow-wrap: anywhere;
}
</style>
