<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<CnActionDialog
		:config="config"
		:objectId="objectId"
		:object="row"
		@close="$emit('close')"
		@done="$emit('done', $event)" />
</template>

<script>
import { CnActionDialog } from '@conduction/nextcloud-vue'

/**
 * The library's action dialog acting on one row of a record list.
 *
 * A record list's `rowActions` `open-modal` entry hands the clicked row over
 * as the `row` prop, while its declared props resolve only the page's tokens
 * (`@objectId` is the page record). CnActionDialog reads `@object.<field>`
 * from its `object` prop, so this dialog passes the row on as that record:
 * a request URL can then name both the page record and the row
 * (`/apps/dossiq/api/cases/@objectId/goals/@object.id/close`). Everything
 * else (fields, reason, confirm, done text) is the manifest's `config`.
 *
 * @spec openspec/changes/case-goals/specs/case-goals/spec.md
 */
export default {
	name: 'RowRecordActionDialog',

	components: { CnActionDialog },

	props: {
		/** The CnActionDialog configuration, from the manifest. */
		config: {
			type: Object,
			default: () => ({}),
		},

		/** The page record's id, for `@objectId`. */
		objectId: {
			type: [String, Number],
			default: '',
		},

		/** The record-list row the action was chosen on, for `@object.<field>`. */
		row: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'done'],
}
</script>
