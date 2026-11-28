<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:open="true"
		:name="t('dossiq', 'End role assignment')"
		@update:open="
			(v) => {
				if (!v) $emit('close')
			}
		">
		<div class="end-assignment">
			<p>
				{{
					t(
						'dossiq',
						'Setting an end date closes the assignment. The person retains the role through end-of-day.',
					)
				}}
			</p>
			<div class="form-group">
				<label class="required" for="ea-end">{{
					t('dossiq', 'End date')
				}}</label>
				<input
					id="ea-end"
					type="date"
					class="end-assignment__date"
					:value="endDate"
					@input="endDate = $event.target.value" />
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!endDate"
				@click="$emit('save', endDate)">
				{{ t('dossiq', 'End assignment') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'EndAssignmentDialog',
	components: { NcButton, NcDialog },
	props: {
		// RoleAssignmentsTable passes `:assignment="ending"`. The dialog acts
		// on it through its own emit rather than rendering it, and the declaration
		// is what keeps it a prop instead of a fallthrough attribute.
		// eslint-disable-next-line vue/no-unused-properties
		assignment: { type: Object, required: true },
	},

	emits: ['save', 'close'],
	data() {
		return {
			endDate: new Date().toISOString().slice(0, 10),
		}
	},

	methods: { t },
}
</script>

<style scoped lang="scss">
@use '../styles/_editorForm.scss' as editor;

.end-assignment {
	min-width: 360px;
	padding: 8px 4px;
}

.form-group {
	margin-top: 12px;
}

@include editor.required-labels;

.end-assignment__date {
	@include editor.bordered-input;
}
</style>
