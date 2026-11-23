<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="dq-new-case-from-message">
		<CnActionButtons
			ref="buttons"
			:actions="[]"
			@created="onCreated" />
	</div>
</template>

<script>
import { CnActionButtons } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import manifest from '../manifest.json'

/** The reason the filing carries: the case was made from the message itself. */
export const NEW_CASE_REASON = 'A new case was made from this message'

/**
 * The New case action of dossiq's own pages.
 *
 * @param {object} source The app manifest.
 * @return {object|null} The open-form action, or null.
 * @spec openspec/specs/case-email-integration/spec.md#requirement-a-handler-files-a-logged-message-on-a-case-they-pick
 */
export function findNewCaseAction(source) {
	for (const page of source?.pages || []) {
		const action = (page?.config?.headerActions || []).find((candidate) => candidate?.id === 'new-case' && candidate?.type === 'open-form')
		if (action) {
			return action
		}
	}
	return null
}

/**
 * The New case action seeded from an inbox message: its subject as the title,
 * its text as the description and email as the intake channel. It does not
 * navigate on success, so the message can be filed on the case first.
 *
 * @param {object} action The New case action.
 * @param {object} message The inbox row.
 * @return {object} The seeded action.
 * @spec openspec/specs/case-email-integration/spec.md#requirement-a-handler-files-a-logged-message-on-a-case-they-pick
 */
export function actionFromMessage(action, message) {
	const seeds = { intakeChannel: 'email' }
	if (message?.subject) {
		seeds.title = message.subject
	}
	if (message?.preview) {
		seeds.description = message.preview
	}
	const seeded = { ...action, props: { ...(action.props || {}), ...seeds } }
	delete seeded.onSuccessRoute
	return seeded
}

/**
 * New case from an inbox message: dossiq's own New case dialog, seeded from
 * the message. Once the case exists the message is filed on it, with its
 * sender as a party, and the new case opens.
 *
 * @spec openspec/specs/case-email-integration/spec.md#requirement-a-handler-files-a-logged-message-on-a-case-they-pick
 */
export default {
	name: 'NewCaseFromMessageDialog',

	components: { CnActionButtons },

	props: {
		/** The inbox row: subject, sender, preview. */
		object: { type: Object, default: () => ({}) },
		/** The intake log entry id. */
		objectId: { type: [String, Number], default: '' },
	},

	emits: ['close', 'done'],

	data() {
		return {
			finished: false,
		}
	},

	/**
	 * Open the New case dialog seeded from the message.
	 *
	 * @spec openspec/specs/case-email-integration/spec.md#requirement-a-handler-files-a-logged-message-on-a-case-they-pick
	 */
	async mounted() {
		const action = findNewCaseAction(manifest)
		const buttons = this.$refs.buttons
		if (!action || !buttons) {
			this.$emit('close')
			return
		}
		buttons.$watch('formEntry', (entry) => {
			if (entry === null && !this.finished) {
				this.finished = true
				this.$emit('close')
			}
		})
		await buttons.openForm(actionFromMessage(action, this.object))
	},

	methods: {
		/**
		 * The dialog saved a case: file the message on it and open it.
		 *
		 * @param {object} created The created case.
		 * @spec openspec/specs/case-email-integration/spec.md#requirement-a-handler-files-a-logged-message-on-a-case-they-pick
		 */
		async onCreated(created) {
			this.finished = true
			const caseId = created?.id || created?.['@self']?.id || ''
			if (caseId !== '' && this.objectId !== '') {
				try {
					await axios.post(generateUrl(`/apps/dossiq/api/mail-intake/log/${this.objectId}/file-on-case`), {
						caseId,
						reason: NEW_CASE_REASON,
						addSender: Boolean(this.object?.sender),
						notifyHandler: false,
					})
					showSuccess(t('dossiq', 'The message is on the new case.'))
				} catch {
					showError(t('dossiq', 'The case was made, but the message could not be put on it. Link it from the inbox.'))
				}
			}
			emit('cn:page:refresh', {})
			this.$emit('done', { caseId })
			this.$emit('close')
			if (caseId !== '' && this.$router) {
				this.$router.push({ name: 'CaseDetail', params: { id: caseId } }).catch(() => {})
			}
		},
	},
}
</script>

<style scoped>
/* The action host draws a button bar; only its dialog shows here. */
.dq-new-case-from-message :deep(.cn-action-buttons) {
	display: none;
}
</style>
