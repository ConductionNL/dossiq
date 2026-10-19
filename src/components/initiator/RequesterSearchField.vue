<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The requester of a new case as one search field (board DqNieuweZaak,
  Aanvrager): what is typed searches the person and the company register sets
  together, and the results drop as rows with initials, the name and a second
  line ("Inwoner · Beukenlaan 3", "Organisatie · KvK 24157431").

  A form field: CnFormDialog mounts it for a `widget: "RequesterSearchField"`
  override and it writes the chosen row's uuid, which is what `case.requester`
  holds. The display projection (initiatorType, initiatorSourceId,
  initiatorDisplayName) is filled from that uuid when the case is first
  opened (RequesterProjection).

  @spec openspec/specs/initiator-selection/spec.md
-->
<template>
	<CnSearchSelect
		:modelValue="selectedId"
		:options="options"
		:inputLabel="inputLabel"
		:labelOutside="true"
		:loading="searching"
		:filterable="false"
		:disabled="disabled"
		:placeholder="t('dossiq', 'Name, BSN, trade name or KvK number')"
		data-testid="requester-search-field"
		@search="onSearch"
		@update:modelValue="onPick" />
</template>

<script>
import { CnSearchSelect } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { useObjectStore } from '../../store/modules/object.js'

/** The register sets a requester is searched in, in the order they are listed. */
const SOURCES = ['brpPerson', 'kvkCompany']

/**
 * A register row as a search row.
 *
 * @param {string} schema The register set the row came from.
 * @param {object} row The row.
 * @return {{id: string, label: string, secondary: string, avatar: boolean}|null} The row, or null without an id.
 * @spec openspec/specs/initiator-selection/spec.md
 */
export function requesterRow(schema, row) {
	const id = String(row?.id || row?.['@self']?.id || '')
	if (id === '') {
		return null
	}
	if (schema === 'kvkCompany') {
		const number = String(row.kvkNumber || '')
		return {
			id,
			label: String(row.tradeName || number),
			secondary: [t('dossiq', 'Organisation'), number && t('dossiq', 'CoC {number}', { number })].filter(Boolean).join(' · '),
			avatar: true,
		}
	}
	const residence = row.residence || {}
	const address = [residence.street, residence.houseNumber].filter(Boolean).join(' ')
	return {
		id,
		label: String(row.displayName || ''),
		secondary: [t('dossiq', 'Resident'), address].filter(Boolean).join(' · '),
		avatar: true,
	}
}

export default {
	name: 'RequesterSearchField',

	components: {
		CnSearchSelect,
	},

	props: {
		/** The requester uuid the form holds. */
		modelValue: {
			type: String,
			default: null,
		},

		/** The field definition CnFormDialog hands over. */
		field: {
			type: Object,
			default: () => ({}),
		},

		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			results: [],
			current: null,
			searching: false,
			searchTimer: null,
		}
	},

	computed: {
		/** @spec openspec/specs/initiator-selection/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/** @spec openspec/specs/initiator-selection/spec.md */
		inputLabel() {
			return String(this.field?.label || t('dossiq', 'Requester'))
		},

		/** @spec openspec/specs/initiator-selection/spec.md */
		selectedId() {
			return this.modelValue || null
		},

		/**
		 * The search rows, with the row already chosen kept in the list so the field shows its name.
		 *
		 * @return {Array<object>} The rows.
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		options() {
			if (this.current && !this.results.some((row) => row.id === this.current.id)) {
				return [this.current, ...this.results]
			}
			return this.results
		},
	},

	watch: {
		modelValue: {
			immediate: true,
			/**
			 * Name the requester already on the form.
			 *
			 * @return {Promise<void>}
			 * @spec openspec/specs/initiator-selection/spec.md
			 */
			async handler() {
				this.current = await this.resolve(this.modelValue)
			},
		},
	},

	methods: {
		t,

		/**
		 * Search again a moment after typing stops.
		 *
		 * @param {string} query What was typed.
		 * @return {void}
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		onSearch(query) {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(() => this.runSearch(query), 300)
		},

		/**
		 * Search both register sets and list persons before companies.
		 *
		 * @param {string} query What was typed.
		 * @return {Promise<void>}
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		async runSearch(query) {
			const term = String(query ?? '').trim()
			if (term === '') {
				this.results = []
				return
			}
			this.searching = true
			try {
				const sets = await Promise.all(SOURCES.map((schema) => this.objectStore.fetchCollection(schema, { _search: term, _limit: 10 })
					.then((rows) => (rows || []).map((row) => requesterRow(schema, row)))
					.catch(() => [])))
				this.results = sets.flat().filter(Boolean)
			} finally {
				this.searching = false
			}
		},

		/**
		 * Name a requester uuid: a uuid says nothing about which set it came from, so both are asked.
		 *
		 * @param {string|null} uuid The requester uuid.
		 * @return {Promise<object|null>} The row, or null.
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		async resolve(uuid) {
			if (typeof uuid !== 'string' || uuid === '') {
				return null
			}
			for (const schema of SOURCES) {
				try {
					const row = await this.objectStore.fetchObject(schema, uuid)
					if (row) {
						return requesterRow(schema, row)
					}
				} catch {
					// Not in this set; try the next.
				}
			}
			return null
		},

		/**
		 * Write the chosen row's uuid.
		 *
		 * @param {string|null} id The chosen row's id.
		 * @return {void}
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		onPick(id) {
			this.current = this.options.find((row) => row.id === id) || null
			this.$emit('update:modelValue', id || null)
		},
	},
}
</script>
