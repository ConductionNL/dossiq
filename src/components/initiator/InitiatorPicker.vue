<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Initiator (indiener) picker — cross-source search with Person / Company /
  Contact tabs (brp-kvk-register-sets). Person and Company search the seeded
  brpPerson / kvkCompany register sets through the object store (thin client,
  register tier only — the live-adapter fallback is owned by
  external-integrations-test-environments); Contact searches Nextcloud
  contacts via the core contactsmenu endpoint and degrades to an explicit
  empty state when unavailable.

  Emits `select` with the four fields a case carries for its requester:
  `requester` (the uuid of the chosen register row, empty for a contact)
  plus the `initiatorType` / `initiatorSourceId` / `initiatorDisplayName`
  projection. One write path: the form saves all four together.

  `value` accepts that payload back, or the bare uuid `case.requester`
  holds, so the picker shows the choice already on the case.

  @spec openspec/specs/initiator-selection/spec.md
-->
<template>
	<div class="initiator-picker" data-testid="initiator-picker">
		<div
			v-if="currentChoice"
			class="initiator-picker__current"
			data-testid="initiator-picker-current">
			<component
				:is="typeIcon(currentChoice.type)"
				:size="20"
				class="initiator-picker__result-icon" />
			<span class="initiator-picker__result-name">{{
				currentChoice.displayName
			}}</span>
			<span class="initiator-picker__result-detail">{{
				currentChoice.sourceId
			}}</span>
		</div>

		<div class="initiator-picker__tabs" role="tablist">
			<NcCheckboxRadioSwitch
				v-for="tab in tabs"
				:key="tab.id"
				v-model="activeTab"
				:value="tab.id"
				name="initiator-type"
				type="radio"
				buttonVariant
				buttonVariantGrouped="horizontal">
				{{ tab.label }}
			</NcCheckboxRadioSwitch>
		</div>

		<NcTextField
			v-model="query"
			class="initiator-picker__search"
			:label="t('dossiq', 'Search initiator')"
			:placeholder="searchPlaceholder"
			trailingButtonIcon="close"
			:showTrailingButton="query !== ''"
			@trailingButtonClick="query = ''"
			@update:modelValue="onQueryChanged" />

		<NcLoadingIcon v-if="searching" :size="24" />

		<NcNoteCard
			v-else-if="refusal"
			type="warning"
			data-testid="initiator-picker-refusal">
			<p>{{ refusalHeadline }}</p>
			<p>{{ refusal.message }}</p>
		</NcNoteCard>

		<NcEmptyContent
			v-else-if="query.trim() !== '' && results.length === 0"
			:name="emptyTitle"
			:description="emptyDescription" />

		<ul v-else class="initiator-picker__results">
			<li v-for="result in results" :key="`${result.type}-${result.sourceId}`">
				<button
					type="button"
					class="initiator-picker__result"
					data-testid="initiator-picker-result"
					:aria-pressed="isSelected(result) ? 'true' : 'false'"
					:class="{
						'initiator-picker__result--selected': isSelected(result),
					}"
					@click="select(result)">
					<component
						:is="typeIcon(result.type)"
						:size="20"
						class="initiator-picker__result-icon" />
					<span class="initiator-picker__result-name">{{
						result.displayName
					}}</span>
					<span class="initiator-picker__result-detail">{{
						result.detail
					}}</span>
				</button>
			</li>
		</ul>
	</div>
</template>

<script>
import {
	NcCheckboxRadioSwitch,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import CardAccountMailOutline from 'vue-material-design-icons/CardAccountMailOutline.vue'
import Domain from 'vue-material-design-icons/Domain.vue'
import { resolvePartyByAddress } from '../../services/caseParties.js'
import {
	companyResult,
	isCurrentRequester,
	personResult,
	requesterPayload,
	searchContacts,
} from '../../services/initiatorSearch.js'
import { useObjectStore } from '../../store/modules/object.js'
import {
	readSearchRefusal,
	searchRefusalHeadline,
} from '../../utils/searchRefusal.js'

export default {
	name: 'InitiatorPicker',
	components: {
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		AccountOutline,
		Domain,
		CardAccountMailOutline,
	},

	props: {
		/**
		 * The requester already on the case: the emitted payload, the bare
		 * uuid `case.requester` holds, or null.
		 */
		value: {
			type: [Object, String],
			default: null,
		},
	},

	emits: ['select'],

	data() {
		return {
			activeTab: 'person',
			query: '',
			results: [],
			searching: false,
			searchTimer: null,
			/**
			 * The refusal the register answered the typed term with, or null.
			 *
			 * 🔴 `fetchCollection()` DOES NOT THROW ON A 400. It records the
			 * failure on the store and returns `[]`, so the catch below never
			 * sees a refused search term and the picker used to say "No
			 * results" for a bracket the reader forgot to close.
			 */
			refusal: null,
			/** The row `value` names, resolved for display. */
			resolvedValue: null,
		}
	},

	computed: {
		/** @spec openspec/specs/initiator-selection/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/**
		 * The choice already on the case, as a unified result, so the
		 * picker can show WHO is on it rather than a uuid. Comes from the
		 * emitted payload directly, or from the row resolved for a bare
		 * uuid.
		 *
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		currentChoice() {
			if (
				this.value
				&& typeof this.value === 'object'
				&& this.value.initiatorType
			) {
				return {
					type: this.value.initiatorType,
					sourceId: this.value.initiatorSourceId || '',
					displayName: this.value.initiatorDisplayName || '',
				}
			}
			return this.resolvedValue
		},

		/** @spec openspec/specs/initiator-selection/spec.md */
		tabs() {
			return [
				{ id: 'person', label: t('dossiq', 'Person') },
				{ id: 'company', label: t('dossiq', 'Company') },
				{ id: 'contact', label: t('dossiq', 'Contact') },
			]
		},

		/** @spec openspec/specs/initiator-selection/spec.md */
		searchPlaceholder() {
			switch (this.activeTab) {
				case 'company':
					return t('dossiq', 'Trade name or KvK number')
				case 'contact':
					return t('dossiq', 'Contact name or email')
				default:
					return t('dossiq', 'Name or BSN')
			}
		},

		/** @spec openspec/specs/initiator-selection/spec.md */
		/**
		 * The sentence above a refused term.
		 *
		 * @return {string} The headline, empty when the term was readable.
		 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
		 */
		refusalHeadline() {
			return searchRefusalHeadline(this.refusal, t)
		},

		emptyTitle() {
			return this.activeTab === 'contact'
				? t('dossiq', 'No contacts found')
				: t('dossiq', 'No results')
		},

		/** @spec openspec/specs/initiator-selection/spec.md */
		emptyDescription() {
			return this.activeTab === 'contact'
				? t(
						'dossiq',
						'No matching contacts. The Contacts app may not be installed or holds no matching entries.',
					)
				: t('dossiq', 'No matching records in the seeded register set.')
		},
	},

	watch: {
		value: {
			immediate: true,
			/**
			 * Resolve a newly-bound requester for display.
			 *
			 * @return {void}
			 * @spec openspec/specs/initiator-selection/spec.md
			 */
			handler() {
				this.resolveValue()
			},
		},

		/** @spec openspec/specs/initiator-selection/spec.md */
		activeTab() {
			this.results = []
			if (this.query.trim() !== '') {
				this.runSearch()
			}
		},
	},

	methods: {
		/**
		 * Debounced search trigger for text input.
		 *
		 * @return {void}
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		onQueryChanged() {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(() => this.runSearch(), 300)
		},

		/**
		 * Execute the search against the active source.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		async runSearch() {
			const query = this.query.trim()
			this.refusal = null
			if (query === '') {
				this.results = []
				return
			}
			this.searching = true
			try {
				if (this.activeTab === 'contact') {
					this.results = await searchContacts(query)
					return
				}

				const type = this.activeTab === 'person' ? 'brpPerson' : 'kvkCompany'
				const shape = this.activeTab === 'person' ? personResult : companyResult
				const rows = await this.objectStore.fetchCollection(
					type,
					{ _search: query, _limit: 20 },
				)

				// The term openregister could not parse, said rather than
				// rendered as an empty list. The store holds the refusal
				// because the fetch swallowed it.
				this.refusal = readSearchRefusal({
					error: this.objectStore?.errors?.[type] ?? null,
					term: query,
				})
				this.results = this.refusal === null ? (rows || []).map(shape) : []
			} catch (err) {
				console.error('[InitiatorPicker] search failed', err)
				this.results = []
			} finally {
				this.searching = false
			}
		},

		/**
		 * Whether a result matches the current selection.
		 *
		 * @param {object} result A unified initiator result.
		 * @return {boolean} True when selected.
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		isSelected(result) {
			return isCurrentRequester(this.value, result)
		},

		/**
		 * Resolve a bare `requester` uuid to the row it names, so the
		 * picker can show the current choice on an edit form. Tries the
		 * person set, then the company set: a uuid says nothing about
		 * which one it came from. Best-effort — an unresolvable uuid
		 * simply shows no current choice.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		async resolveValue() {
			if (typeof this.value !== 'string' || this.value === '') {
				this.resolvedValue = null
				return
			}
			const uuid = this.value
			for (const [schema, shape] of [
				['brpPerson', personResult],
				['kvkCompany', companyResult],
			]) {
				try {
					const row = await this.objectStore.fetchObject(schema, uuid)
					if (row) {
						this.resolvedValue = shape(row)
						return
					}
				} catch {
					// Wrong set, or a uuid this instance cannot resolve.
				}
			}
			this.resolvedValue = null
		},

		/**
		 * Emit the picked initiator as the four fields the case carries:
		 * the canonical `requester` uuid plus its display projection.
		 *
		 * @param {object} result A unified initiator result.
		 * @return {void}
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		async select(result) {
			this.$emit('select', requesterPayload(await this.deduplicated(result)))
		},

		/**
		 * The party already holding this person's address, when one does.
		 *
		 * A Nextcloud contact has no register row, so picking one used to
		 * leave `requester` empty and the case named the person only by a
		 * display string. The next case naming the same person did the same,
		 * and the melder who wrote twice from one address became two records
		 * that no merge had any reason to find. OpenRegister answers who
		 * already holds an address, so this asks BEFORE a second record is
		 * created, which is the same call `docs/Features/parties.md` asks of
		 * integriq's BRP and KvK adapters.
		 *
		 * A register row this picker chose is already the record, so it is
		 * returned untouched: resolving it would be asking whether the thing
		 * in front of us exists.
		 *
		 * @param {object} result A unified initiator result.
		 * @return {Promise<object>} The result, pointed at the existing party when there is one.
		 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
		 */
		async deduplicated(result) {
			if (!result || result.objectId) {
				return result
			}
			const address = String(result.detail || '').trim()
			if (address === '' || !address.includes('@')) {
				return result
			}
			const party = await resolvePartyByAddress(address)
			if (!party) {
				return result
			}
			return {
				...result,
				objectId: party.id || party['@self']?.id || party.uuid || null,
			}
		},

		/**
		 * Icon component per initiator type.
		 *
		 * @param {string} type person | company | contact.
		 * @return {string} Icon component name.
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		typeIcon(type) {
			switch (type) {
				case 'company':
					return 'Domain'
				case 'contact':
					return 'CardAccountMailOutline'
				default:
					return 'AccountOutline'
			}
		},
	},
}
</script>

<style scoped lang="scss">
.initiator-picker {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);

	&__tabs {
		display: flex;
	}

	&__current {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		padding: calc(var(--default-grid-baseline) * 2);
		border-radius: var(--border-radius);
		background-color: var(--color-background-hover);
	}

	&__results {
		max-height: 40vh;
		overflow-y: auto;
	}

	&__result {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		width: 100%;
		padding: calc(var(--default-grid-baseline) * 2);
		border: none;
		border-radius: var(--border-radius);
		background: transparent;
		text-align: start;
		cursor: pointer;

		&:hover,
		&:focus-visible {
			background-color: var(--color-background-hover);
		}

		&--selected {
			background-color: var(--color-primary-element-light);
		}
	}

	&__result-name {
		font-weight: bold;
	}

	&__result-detail {
		color: var(--color-text-maxcontrast);
	}
}
</style>
