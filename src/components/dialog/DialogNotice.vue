<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  A dialog's refusal or result banner, drawn here only while the installed
  CnDialog does not draw `notices` itself (see utils/dialogNotice.js).

  @spec openspec/specs/burger-notifications/spec.md
-->
<template>
	<div
		v-if="notice && !drawnByDialog"
		class="dialog-notice"
		:role="notice.role"
		data-testid="dialog-notice">
		<NcNoteCard :type="notice.variant" :heading="notice.title">
			{{ notice.text }}
		</NcNoteCard>
	</div>
</template>

<script>
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { dialogDrawsNotices } from '../../utils/dialogNotice.js'

export default {
	name: 'DialogNotice',

	components: { NcNoteCard },

	props: {
		/** The notice from `dialogNotice()`, or null. */
		notice: {
			type: Object,
			default: null,
		},
	},

	computed: {
		/** @spec openspec/specs/burger-notifications/spec.md */
		drawnByDialog() {
			return dialogDrawsNotices()
		},
	},
}
</script>

<style scoped>
.dialog-notice :deep(.notecard) {
	margin: 0;
}
</style>
