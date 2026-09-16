<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Plan something on your own day, with no case behind it.

  🔴 IT IS NOT A CASE. A caseless case would enter the open-case count, the
  case list and every report that groups by case type, and no filter put
  afterwards takes it back out. This writes a calendar event on the reader's
  own calendar and nothing else, which is why there is no case type field here
  and no way to add one.

  The templates are a starting point, not a type: they set a length and a
  suggested title, and nothing downstream branches on which one was picked.

  @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Plan an item')"
		data-testid="plan-item-dialog"
		@closing="$emit('close')">
		<div class="plan-item">
			<p class="plan-item__explainer">
				{{
					t(
						'dossiq',
						'This goes on your own calendar and on your queue. It is not a case.',
					)
				}}
			</p>

			<NcSelect
				v-model="template"
				:options="templateOptions"
				:inputLabel="t('dossiq', 'Template')"
				:clearable="true"
				data-testid="plan-item-template"
				@update:modelValue="applyTemplate" />

			<NcTextField
				v-model="title"
				:label="t('dossiq', 'What is it')"
				data-testid="plan-item-title" />

			<NcTextField
				v-model="startsAt"
				type="datetime-local"
				:label="t('dossiq', 'When')"
				data-testid="plan-item-when" />

			<NcNoteCard v-if="failure" type="error" data-testid="plan-item-error">
				{{ failure }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton variant="primary" :disabled="saving || !canSave" @click="save">
				{{ t('dossiq', 'Plan it') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { planItem } from '../services/personalQueueApi.js'

/** The templates the backend declares, with what each one is called here. */
const TEMPLATES = [
	{ id: 'call-back', label: 'Call someone back' },
	{ id: 'prepare-decision', label: 'Prepare a decision' },
	{ id: 'site-visit', label: 'Site visit' },
	{ id: 'catch-up', label: 'Catch up on a case' },
]

export default {
	name: 'PlanItemDialog',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	emits: ['close', 'planned'],

	data() {
		return {
			template: null,
			title: '',
			startsAt: '',
			saving: false,
			failure: '',
		}
	},

	computed: {
		/**
		 * @return {Array} The templates, translated.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		templateOptions() {
			return TEMPLATES.map((entry) => ({
				id: entry.id,
				label: t('dossiq', entry.label),
			}))
		},

		/**
		 * @return {boolean} TRUE when there is enough to plan.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		canSave() {
			return this.title.trim() !== '' && this.startsAt.trim() !== ''
		},
	},

	methods: {
		t,

		/**
		 * Take the template's suggested title, unless the reader wrote one.
		 *
		 * @param {object} chosen The chosen template.
		 * @return {void}
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		applyTemplate(chosen) {
			if (chosen?.label && this.title.trim() === '') {
				this.title = chosen.label
			}
		},

		/**
		 * Plan it.
		 *
		 * @return {Promise<void>} When the write has finished.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		async save() {
			this.saving = true
			this.failure = ''
			try {
				await planItem({
					title: this.title,
					startsAt: this.startsAt,
					template: this.template?.id ?? '',
				})
				this.$emit('planned')
			} catch (error) {
				this.failure =
					error?.response?.data?.error
					|| t('dossiq', 'The item could not be planned.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.plan-item {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.plan-item__explainer {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
