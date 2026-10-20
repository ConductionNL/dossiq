<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  A party as a form field (board DqNieuweZaak, Aanvrager): the library's
  CnPartyPicker over the person and company register sets, writing the chosen
  row's uuid back to the form. It writes the uuid only: the case page's
  RequesterProjection derives initiatorType, initiatorSourceId and
  initiatorDisplayName from it, so there is one place that projection is made.
  A Nextcloud contact has no register row and so no uuid to store; contacts
  stay in the start-a-case picker.

  @spec openspec/specs/initiator-selection/spec.md
-->
<template>
	<CnPartyPicker
		:modelValue="modelValue"
		:sources="sources"
		:inputLabel="String((field && field.label) || t('dossiq', 'Person or organisation'))"
		:labelOutside="true"
		:placeholder="t('dossiq', 'Name, BSN, trade name or KvK number')"
		:disabled="disabled"
		data-testid="initiator-picker-search"
		@update:modelValue="(id) => $emit('update:modelValue', id)" />
</template>

<script>
import { CnPartyPicker } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { registerPartySources } from './partySources.js'

export default {
	name: 'PartySearchField',

	components: { CnPartyPicker },

	props: {
		/** The field descriptor the form hands a field widget. */
		field: {
			type: Object,
			default: null,
		},

		/** The chosen row's uuid. */
		modelValue: {
			type: [String, Number],
			default: null,
		},

		/** Disable the field. */
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			sources: registerPartySources(),
		}
	},

	methods: {
		t,
	},
}
</script>
