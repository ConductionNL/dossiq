<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:open="true"
		:name="t('procest', 'End role assignment')"
		@update:open="
			(v) => {
				if (!v) $emit('close')
			}
		">
		<div class="end-assignment">
			<p>
				{{
					t(
						'procest',
						'Setting an end date closes the assignment. The person retains the role through end-of-day.',
					)
				}}
			</p>
			<div class="form-group">
				<label class="required" for="ea-end">{{
					t('procest', 'End date')
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
				{{ t('procest', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!endDate"
				@click="$emit('save', endDate)">
				{{ t('procest', 'End assignment') }}
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

<style scoped>
.end-assignment {
	min-width: 360px;
	padding: 8px 4px;
}

.form-group {
	margin-top: 12px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: 500;
}

.form-group label.required::after {
	content: ' *';
	color: var(--color-error);
}

.end-assignment__date {
	width: 100%;
	padding: 8px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
}
</style>
