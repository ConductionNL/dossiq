<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Initiator picker modal for the case create flow (StartCaseWidget):
  optional selection — the case remains creatable without an initiator
  ("Skip"). Confirming emits the same payload the picker emits: the
  canonical `requester` uuid plus the `initiatorType` /
  `initiatorSourceId` / `initiatorDisplayName` projection, ready to write
  onto the case in one save. Modal isolation per ADR-004: lives in
  src/modals/.

  @spec openspec/specs/initiator-selection/spec.md
-->
<template>
	<NcModal
		size="normal"
		:name="t('dossiq', 'Who is the initiator?')"
		@close="$emit('close')">
		<div class="initiator-picker-modal">
			<!-- No <h2> here: NcModal's `name` prop already renders the dialog
			     heading (h2.modal-header__name) and wires it as the dialog's
			     accessible name. Repeating it in the body announced the title
			     twice to a screen reader and made every
			     getByRole('heading', …) query ambiguous. -->
			<p class="initiator-picker-modal__hint">
				{{
					t(
						'dossiq',
						'Link the case to the person, company, or contact who submitted it. You can also skip this and add the initiator later.',
					)
				}}
			</p>

			<CnPartyPicker
				:modelValue="selection ? (selection.requester || selection.initiatorSourceId) : null"
				:sources="sources"
				:selectedLabel="selection ? selection.initiatorDisplayName : ''"
				:inputLabel="t('dossiq', 'Search initiator')"
				:labelOutside="true"
				:placeholder="t('dossiq', 'Name, BSN, trade name or KvK number')"
				data-testid="initiator-picker"
				@select="onSelect" />

			<div
				v-if="selection"
				class="initiator-picker-modal__selection"
				data-testid="initiator-picker-selection">
				{{ t('dossiq', 'Selected:') }}
				<strong>{{ selection.initiatorDisplayName }}</strong> ({{
					selection.initiatorSourceId
				}})
			</div>

			<div class="initiator-picker-modal__actions">
				<NcButton variant="tertiary" @click="$emit('skip')">
					{{ t('dossiq', 'Skip') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="!selection"
					@click="$emit('confirm', selection)">
					{{ t('dossiq', 'Use as initiator') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { CnPartyPicker } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcModal } from '@nextcloud/vue'
import { initiatorChoice } from '../services/initiatorSearch.js'

/**
 * Where a case's initiator is searched: the brpPerson and kvkCompany register
 * sets and the reader's Nextcloud contacts. A person shows birth date and BSN
 * and a company its KvK number, so two parties of one name are told apart.
 * The `id` of each is the kind a choice is recorded as (`initiatorType`).
 *
 * @type {Array<object>}
 */
const PARTY_SOURCES_WITH_CONTACTS = Object.freeze([
	{ id: 'person', register: 'dossiq', schema: 'brpPerson', lookup: { url: '/apps/dossiq/api/people/lookup', when: '^[0-9]{9}$' }, label: 'Resident', title: '{displayName}', secondary: '{birth.date}' },
	{ id: 'company', register: 'dossiq', schema: 'kvkCompany', label: 'Organisation', title: '{tradeName}', secondary: 'KVK {kvkNumber}' },
	{ id: 'contact', type: 'contacts', label: 'Contact' },
])

export default {
	name: 'InitiatorPickerModal',
	components: {
		NcButton,
		NcModal,
		CnPartyPicker,
	},

	emits: ['close', 'confirm', 'skip'],

	data() {
		return {
			selection: null,
			sources: PARTY_SOURCES_WITH_CONTACTS,
		}
	},

	methods: {
		t,

		/**
		 * Take the picked party as the four fields the case carries, pointed
		 * at the party already holding a contact's address when there is one.
		 *
		 * @param {{source: string, row: object}|null} picked What the picker chose.
		 * @return {Promise<void>}
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		async onSelect(picked) {
			this.selection = picked ? await initiatorChoice(picked.source, picked.row) : null
		},
	},
}
</script>

<style scoped lang="scss">
.initiator-picker-modal {
	padding: calc(var(--default-grid-baseline) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__actions {
		display: flex;
		justify-content: flex-end;
		gap: calc(var(--default-grid-baseline) * 2);
	}
}
</style>
