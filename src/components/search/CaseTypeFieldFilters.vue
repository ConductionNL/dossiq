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
					<span class="case-type-field-filters__label">{{
						definition.name
					}}</span>
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
					v-else-if="
						controlOf(definition) === 'range'
						|| controlOf(definition) === 'dateRange'
					"
					class="case-type-field-filters__field">
					<legend class="case-type-field-filters__label">
						{{ definition.name }}
					</legend>
					<input
						:type="controlOf(definition) === 'range' ? 'number' : 'date'"
						:aria-label="fromLabel(definition)"
						:data-testid="`field-filter-${idOf(definition)}-from`"
						:value="valueOf(definition).gte || ''"
						@input="
							setValue(definition, {
								...valueOf(definition),
								gte: $event.target.value,
							})
						" />
					<input
						:type="controlOf(definition) === 'range' ? 'number' : 'date'"
						:aria-label="toLabel(definition)"
						:data-testid="`field-filter-${idOf(definition)}-to`"
						:value="valueOf(definition).lte || ''"
						@input="
							setValue(definition, {
								...valueOf(definition),
								lte: $event.target.value,
							})
						" />
				</fieldset>

				<label v-else class="case-type-field-filters__field">
					<span class="case-type-field-filters__label">{{
						definition.name
					}}</span>
					<input
						type="text"
						:data-testid="`field-filter-${idOf(definition)}`"
						:value="valueOf(definition).eq || ''"
						@input="setValue(definition, { eq: $event.target.value })" />
				</label>
			</template>
		</div>

		<NcNoteCard
			v-if="refusal"
			type="warning"
			class="case-type-field-filters__refusal"
			data-testid="case-type-field-filters-refusal">
			<p>{{ refusalHeadline }}</p>
			<p class="case-type-field-filters__refusal-reason">
				{{ refusal.message }}
			</p>
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
 * 🔴 THE FILTERS TRAVEL TWICE, AND ONLY ONE OF THE TWO REACHES THE SERVER.
 * The route query is the record: it is what a shared link carries and what
 * `readListFilters()` hands a whole-result bulk act. The FETCH is reached
 * through the list's own `onFilterChange`, because `CnIndexPage` builds its
 * filters with `resolveQueryFilters()`, which skips every `_`-prefixed key.
 * Writing the route alone leaves the list answering the unfiltered set under
 * a URL that says it is filtered, which is the exact failure the refusal
 * notice below exists to prevent, arriving by a quieter door.
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

	inject: {
		/**
		 * The index page's sidebar state, which is also the only channel an
		 * app component has into the list's own fetch.
		 *
		 * 🔴 THE ROUTE QUERY ALONE DOES NOT REACH THE SERVER, and that is why
		 * this inject exists rather than the bar simply writing the URL.
		 * `CnIndexPage` turns `$route.query` into fetch filters through
		 * `resolveQueryFilters()`, which SKIPS every key beginning with `_`:
		 * the underscore namespace is the library's own (`_search`, `_page`,
		 * `_limit`, `_order`). So a `_related[…]` key written to the route is
		 * dropped before the request is built, and the list answers the
		 * UNFILTERED set while the URL says it is filtered. Measured against
		 * the installed `@conduction/nextcloud-vue` 3.4.0 and asserted in
		 * `tests/vitest/caseTypeFieldFilters.spec.js`.
		 *
		 * `onFilterChange({ key, values })` is the channel the facet sidebar
		 * already uses, and `useListView.buildParams()` copies its keys into
		 * the request verbatim, which is what carries the brackets through.
		 */
		listSidebarState: { from: 'sidebarState', default: null },
	},

	data() {
		return {
			/** The definitions of the case type currently picked. */
			definitions: [],
			/** What the handler has entered, by definition id. */
			values: {},
			/** The `_related` keys last handed to the list. */
			applied: {},
		}
	},

	computed: {
		/**
		 * The case type the folder sidebar has narrowed to, or an empty string.
		 *
		 * @return {string} The case type id.
		 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
		 */
		caseType() {
			const picked = this.$route?.query?.caseType

			return typeof picked === 'string' ? picked : ''
		},

		/**
		 * The definitions this case type offers as filters.
		 *
		 * @return {Array<object>} The definitions.
		 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
		 */
		offered() {
			return filterableDefinitions(this.definitions)
		},

		/**
		 * The shared object store, where the list's own failure is recorded.
		 *
		 * @return {object} The store.
		 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
		 */
		objectStore() {
			return useObjectStore()
		},

		/**
		 * The refusal to show, or null when the last fetch was not refused.
		 *
		 * @return {object|null} The refusal.
		 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
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
		 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
		 */
		refusalHeadline() {
			if (this.refusal?.name) {
				return t(
					'dossiq',
					'The filter on {field} was refused, so this list is not an answer.',
					{
						field: this.refusal.name,
					},
				)
			}

			return t(
				'dossiq',
				'One of the field filters was refused, so this list is not an answer.',
			)
		},

		/**
		 * The option that asks for nothing.
		 *
		 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
		 */
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
			 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
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

	mounted() {
		// A shared link lands with the blocks already on the route, and the
		// list would otherwise fetch without them: the bar is mounted by the
		// page, so nothing else replays them.
		const query = this.$route?.query || {}
		const carried = {}
		relatedKeysIn(query).forEach((key) => {
			carried[key] = query[key]
		})

		if (Object.keys(carried).length > 0) {
			this.$nextTick(() => this.sendToList(carried))
		}
	},

	methods: {
		/**
		 * The id a definition is addressed by.
		 *
		 * @param {object} definition The definition.
		 * @return {string} The id.
		 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
		 */
		idOf(definition) {
			return definitionId(definition)
		},

		/**
		 * The control a definition is asked for with.
		 *
		 * @param {object} definition The definition.
		 * @return {string} The control.
		 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
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
		 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
		 */
		fromLabel(definition) {
			return t('dossiq', '{field}, from', { field: definition?.name || '' })
		},

		/**
		 * The accessible name of the upper bound of a range.
		 *
		 * @param {object} definition The definition.
		 * @return {string} The label.
		 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
		 */
		toLabel(definition) {
			return t('dossiq', '{field}, up to', { field: definition?.name || '' })
		},

		/**
		 * What the handler has entered for one definition.
		 *
		 * @param {object} definition The definition.
		 * @return {object} The value.
		 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
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
		 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
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
		 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
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
		 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
		 */
		applyFilters() {
			const entries = this.offered.map((definition) => ({
				definitionId: this.idOf(definition),
				value: this.valueOf(definition),
			}))

			const related = buildRelatedFilters(entries)
			this.pushQuery(related)
			this.sendToList(related)
		},

		/**
		 * Hand the compiled blocks to the list, so the fetch carries them.
		 *
		 * One `onFilterChange` per key that actually changed, because each one
		 * refetches: replaying every key on every keystroke would ask the
		 * server the same question four times. A key that has gone is sent
		 * with an empty value list, which is how `useListView` is told to drop
		 * it rather than to filter on the empty string.
		 *
		 * @param {object} related The compiled `_related` keys.
		 * @return {void}
		 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
		 */
		sendToList(related) {
			const onFilterChange = this.listSidebarState?.onFilterChange
			if (typeof onFilterChange !== 'function') {
				// The list has not wired its sidebar yet, or this bar is
				// mounted outside one. The route still records what was asked
				// for, so nothing is lost silently.
				return
			}

			const previous = this.applied
			Object.keys(previous).forEach((key) => {
				if (related[key] === undefined) {
					onFilterChange({ key, values: [] })
				}
			})

			Object.entries(related).forEach(([key, value]) => {
				if (previous[key] !== value) {
					onFilterChange({ key, values: [String(value)] })
				}
			})

			this.applied = { ...related }
		},

		/**
		 * Take every `_related` key off the route.
		 *
		 * @return {void}
		 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
		 */
		clearRelated() {
			this.pushQuery({})
			this.sendToList({})
		},

		/**
		 * Replace the route's `_related` keys with the ones given.
		 *
		 * @param {object} related The keys to set.
		 * @return {void}
		 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
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
