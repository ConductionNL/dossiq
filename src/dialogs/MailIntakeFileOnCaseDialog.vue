<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  File one received message on a case by hand.

  Lifted out of MailIntakeLogView, which had it inline. ADR-004 wants a dialog
  in its own file: inline, its state is the list view's state, so the two
  cannot be reasoned about or reused apart.

  🔴 IT RECORDS ONE CORRECTION, NOT A RULE. Filing a message somewhere the
  matcher did not put it says what this person did with this message. It
  changes no matching rule, and the note says so, because a handler who thinks
  they have taught the matcher something will stop correcting the next one.

  🔴 A RESPONSE IS NOT A RESULT. The endpoint answers 403 for a case this
  caller may not read. Closing on that would tell the handler the message moved
  when it did not, so the refusal is rendered and the dialog stays open.

  @spec openspec/specs/case-email-integration/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'File this message on a case')"
		size="normal"
		data-testid="intake-log-file-dialog"
		@closing="$emit('close')">
		<div class="intake-log-file-on-case">
			<NcNoteCard v-if="entry.case" type="info">
				{{
					t(
						'dossiq',
						'The matcher filed this message on {case}. Filing it elsewhere records that you overrode it; it changes no matching rule.',
						{ case: entry.case },
					)
				}}
			</NcNoteCard>

			<NcTextField
				:modelValue="caseId"
				:label="t('dossiq', 'Case')"
				data-testid="intake-log-case-id"
				@update:modelValue="(v) => (caseId = v)" />

			<NcTextField
				:modelValue="reason"
				:label="t('dossiq', 'Why this case')"
				data-testid="intake-log-file-reason"
				@update:modelValue="(v) => (reason = v)" />

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<div class="intake-log-file-on-case__actions">
				<NcButton
					variant="primary"
					:disabled="busy || !caseId || !reason"
					data-testid="intake-log-file-confirm"
					@click="confirm">
					{{ t('dossiq', 'File it here') }}
				</NcButton>
				<NcButton @click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default {
	name: 'MailIntakeFileOnCaseDialog',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcTextField,
	},

	props: {
		/** The log entry being filed. */
		entry: {
			type: Object,
			required: true,
		},

		/** The entry's id, as the list reads it. */
		entryId: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'filed'],

	data() {
		return {
			// PRE-FILLED WITH WHERE IT IS, not blank: the common gesture is
			// correcting a match, and a handler who has to retype the right
			// case beside a field that forgot the wrong one cannot see what
			// they are changing.
			caseId: String(this.entry.case || ''),
			reason: '',
			error: '',
			busy: false,
		}
	},

	methods: {
		t,

		/**
		 * File the message on the case the handler picked.
		 *
		 * The reason is REQUIRED by the endpoint, and the button is disabled
		 * without one, so the refusal is visible before the click rather than
		 * after it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/case-email-integration/spec.md
		 */
		async confirm() {
			this.busy = true
			this.error = ''
			try {
				const response = await fetch(
					generateUrl(
						`/apps/dossiq/api/mail-intake/log/${this.entryId}/file-on-case`,
					),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({
							caseId: this.caseId,
							reason: this.reason,
						}),
					},
				)

				if (!response.ok) {
					const body = await response.json().catch(() => ({}))
					this.error =
						body.message === 'Not authorized'
							? t(
									'dossiq',
									'You cannot read that case, so the message was not filed on it.',
								)
							: t(
									'dossiq',
									'The message was not filed. Check the case number.',
								)
					return
				}

				this.$emit('filed')
			} catch {
				this.error = t('dossiq', 'The message was not filed.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>
