<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Remind a colleague about this case on a date.

  Three fields, because a reminder is three things: who it is for, when it is
  due, and what it says. There is no fourth. A description, a priority and a
  checklist all belong to the task the reminder becomes, and every one of them
  offered here would turn a two-second gesture into a form.

  A REMINDER IS A TASK, AND NOTHING HERE MAKES IT SPECIAL. It is created
  through the same engine store every other task goes through, so it shows on
  Tasks, on the assignee's My work and on this case's Work tab, it is claimed
  and completed with the same verbs, and the colleague hears about it through
  the platform's own assignment notification. The only thing that marks it is
  `kind: reminder`, which the engine carries and indexes and treats no
  differently, so the Tasks sidebar can pick reminders out and nothing else
  has to know the word.

  There is no reminder job and no reminder schema for the same reason. The
  engine's due window already answers "what is coming up", and a second clock
  in dossiq would disagree with it the first time one of them was wrong.

  Who defaults to YOU. Most reminders are the one you set for yourself, and a
  picker that opens empty makes that case the slow one. It is a user id typed
  in, which is this app's convention for naming a colleague (the same field
  Reassign uses), and the server refuses a name it cannot resolve.

  It reads the case from the ROUTE, because an open-modal action forwards its
  props verbatim and `@objectId` would arrive as that literal string.

  @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Set a reminder')"
		data-testid="case-remind-dialog"
		@closing="$emit('close')">
		<div class="remind">
			<p class="remind__explainer">
				{{
					t(
						'dossiq',
						'The reminder becomes a task on this case. The colleague is notified and sees it on their work list.',
					)
				}}
			</p>

			<NcTextField
				v-model="assignee"
				data-testid="case-remind-assignee"
				:label="t('dossiq', 'User id of the colleague')" />

			<NcDateTimePicker
				v-model="date"
				data-testid="case-remind-date"
				type="date"
				:min="earliest"
				:label="t('dossiq', 'Date')" />

			<NcTextField
				v-model="title"
				data-testid="case-remind-title"
				:label="t('dossiq', 'What to remind about')" />

			<p
				v-if="error"
				class="remind__error"
				data-testid="case-remind-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="case-remind-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="case-remind-confirm"
				variant="primary"
				:disabled="!canConfirm"
				@click="confirm">
				{{ t('dossiq', 'Set reminder') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePicker from '@nextcloud/vue/components/NcDateTimePicker'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { useEngineTaskStore } from '../store/modules/engineTask.js'
import {
	defaultReminderDate,
	isReminderComplete,
	reminderPayload,
} from '../utils/reminderHelpers.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'RemindDialog',

	components: {
		NcButton,
		NcDateTimePicker,
		NcDialog,
		NcTextField,
	},

	props: {
		/**
		 * The case to remind about. Absent when the manifest opened the
		 * dialog: an `open-modal` action carries no object context, so the
		 * route answers.
		 */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	/**
	 * The engine task store, which is where a reminder actually lives.
	 *
	 * @return {object} The store, exposed to the options API half.
	 * @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
	 */
	setup() {
		return { tasks: useEngineTaskStore() }
	},

	data() {
		const earliest = defaultReminderDate()
		return {
			assignee: getCurrentUser()?.uid ?? '',
			date: earliest,
			title: '',
			busy: false,
			error: '',
			earliest,
		}
	},

	computed: {
		/**
		 * @return {string} The case this dialog acts on.
		 * @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
		 */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},

		/**
		 * @return {string} The chosen date as YYYY-MM-DD, or the empty string.
		 * @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
		 */
		isoDate() {
			if (!this.date) {
				return ''
			}
			if (typeof this.date === 'string') {
				return this.date.slice(0, 10)
			}
			const picked = new Date(this.date)
			if (Number.isNaN(picked.getTime())) {
				return ''
			}
			return picked.toISOString().slice(0, 10)
		},

		/**
		 * @return {object} The form, in the shape the helpers read.
		 * @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
		 */
		form() {
			return {
				assignee: this.assignee,
				date: this.isoDate,
				title: this.title,
			}
		},

		/**
		 * @return {boolean} Whether the reminder may be sent.
		 * @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
		 */
		canConfirm() {
			return (
				this.busy === false
				&& this.targetCaseId !== ''
				&& isReminderComplete(this.form, this.earliest)
			)
		},
	},

	methods: {
		t,

		/**
		 * Create the reminder as an engine task.
		 *
		 * The store answers null on a refusal and puts the server's sentence
		 * on `error`, so a refused write is shown rather than read as a quiet
		 * success. That distinction is the whole reason this does not just
		 * close the dialog and hope.
		 *
		 * @return {Promise<void>} Nothing.
		 * @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
		 */
		async confirm() {
			if (!this.canConfirm) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const created = await this.tasks.create(
					reminderPayload(this.form, this.targetCaseId),
				)
				if (created === null) {
					this.error =
						this.tasks.error
						|| t('dossiq', 'The reminder could not be set.')
					return
				}
				emit(PAGE_REFRESH)
				this.$emit('close')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.remind {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.remind__explainer {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.remind__error {
	color: var(--color-error);
	margin: 0;
}
</style>
