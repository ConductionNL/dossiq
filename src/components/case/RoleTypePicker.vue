<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Which role somebody takes on this case.

	The list is this case type's own role types first, then the roles every
	case type offers, of which the authorised representative of Awb 2:1 is the
	one this change is about. `roleTypeOptions.js` decides the order and the
	deduplication; this component only asks and draws.

	🔴 A FAILED READ SAYS SO AND OFFERS NOTHING. An empty picker tells a handler
	this instance declares no roles, which is a different answer from "we could
	not ask OpenRegister" and would have them adding a role type that already
	exists.

	`inputLabel` rather than a `<label>` beside the select: NcSelect wires its
	own accessible name from that prop, and a manual label leaves the combobox
	unnamed for a screen reader (WCAG 2.2 AA, 1.3.1 and 4.1.2).

	@spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
-->
<template>
	<div class="role-type-picker" data-testid="role-type-picker">
		<NcLoadingIcon v-if="loading" :size="20" />

		<NcNoteCard
			v-else-if="failed"
			type="warning"
			data-testid="role-type-picker-failed">
			{{
				t(
					'dossiq',
					'The roles this case takes could not be read. Nothing has been changed on the case.',
				)
			}}
		</NcNoteCard>

		<NcSelect
			v-else
			:modelValue="selected"
			:options="options"
			:inputLabel="t('dossiq', 'Role')"
			:placeholder="t('dossiq', 'Pick a role')"
			label="label"
			data-testid="role-type-picker-select"
			@update:modelValue="pick" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import {
	fetchCaseTypeOf,
	fetchRoleTypes,
	offeredRoleTypes,
} from '../../services/roleTypeOptions.js'

export default {
	name: 'RoleTypePicker',

	components: { NcLoadingIcon, NcNoteCard, NcSelect },

	props: {
		/** The role type already chosen: a uuid, or null. */
		value: {
			type: [Object, String],
			default: null,
		},

		/** The case the party is being added to. */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['select'],

	data() {
		return {
			loading: true,
			failed: false,
			options: [],
		}
	},

	computed: {
		/**
		 * The option the current value names.
		 *
		 * @return {object|null} The option, null when nothing is chosen yet.
		 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
		 */
		selected() {
			const id = String(this.value?.id || this.value || '')
			return this.options.find((option) => option.id === id) || null
		},
	},

	/**
	 * Read the roles this case offers.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * Ask for the role types and the case's own type, then order them.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-a-gemachtigde-role-req-role-009
		 */
		async load() {
			this.loading = true
			this.failed = false

			const rows = await fetchRoleTypes()
			if (rows === null) {
				this.failed = true
				this.loading = false
				return
			}

			const id = String(this.caseId || this.$route?.params?.id || '')
			this.options = offeredRoleTypes(rows, await fetchCaseTypeOf(id))
			this.loading = false
		},

		/**
		 * Hand the chosen role type's uuid back to the form.
		 *
		 * @param {object|null} option The chosen option.
		 * @return {void}
		 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
		 */
		pick(option) {
			this.$emit('select', option ? option.id : null)
		},
	},
}
</script>

<style scoped>
.role-type-picker {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
}
</style>
