<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Confirming one status transition on the case page.

  The comment is optional; the result is not, when the transition closes the
  case and its case type offers result types. The confirm button is disabled
  until one is picked rather than submitting a request the engine will refuse:
  the refusal and the disabled button say the same thing, and only one of them
  costs a round trip.

  A guard the engine refuses is shown HERE, inside the dialog, beside the
  button that caused it. Toasting it would put the reason somewhere else than
  the gesture, and the case would look unchanged for no stated reason.

  @spec openspec/specs/status-transition-engine/spec.md
-->
<template>
	<NcDialog
		:name="dialogName"
		data-testid="case-transition-dialog"
		@closing="$emit('close')">
		<div class="case-transition-dialog">
			<p v-if="closing" class="case-transition-dialog__closing">
				{{ t('dossiq', 'This moves the case to a final status.') }}
			</p>

			<NcSelect
				v-if="closing && resultTypes.length > 0"
				v-model="result"
				data-testid="case-transition-result"
				:options="resultTypes"
				:inputLabel="t('dossiq', 'Result')"
				:placeholder="t('dossiq', 'Pick the result')"
				label="label"
				trackBy="id" />

			<NcTextArea
				v-model="comment"
				data-testid="case-transition-comment"
				:label="t('dossiq', 'Comment (optional)')" />

			<p
				v-if="error"
				class="case-transition-dialog__error"
				data-testid="case-transition-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="case-transition-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="case-transition-confirm"
				variant="primary"
				:disabled="!canConfirm"
				@click="confirm">
				{{ t('dossiq', 'Confirm') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import {
	buildTransitionPayload,
	canConfirmTransition,
	refusalMessage,
} from '../utils/caseLifecycleHelpers.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseTransitionConfirmDialog',

	components: { NcButton, NcDialog, NcSelect, NcTextArea },

	props: {
		/** The case being moved. */
		caseId: {
			type: String,
			required: true,
		},

		/** The transition the handler pressed ({id, label, toStatus}). */
		transition: {
			type: Object,
			required: true,
		},

		/** Whether the target status closes the case. */
		closing: {
			type: Boolean,
			default: false,
		},

		/** The case type's result types, as {id, label}. */
		resultTypes: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['close'],

	data() {
		return {
			comment: '',
			result: null,
			error: '',
			busy: false,
		}
	},

	computed: {
		/** @spec openspec/specs/status-transition-engine/spec.md */
		dialogName() {
			return t('dossiq', 'Move the case: {label}', {
				label: String(this.transition?.label ?? ''),
			})
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		canConfirm() {
			return canConfirmTransition({
				closing: this.closing,
				resultTypes: this.resultTypes,
				resultTypeId: this.result?.id ?? '',
				busy: this.busy,
			})
		},
	},

	methods: {
		t,

		/**
		 * Post the transition, and keep the dialog open on a refusal so the
		 * reason stays beside the gesture that caused it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		async confirm() {
			if (!this.canConfirm) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				await axios.post(
					generateUrl(`/apps/dossiq/api/case/${encodeURIComponent(this.caseId)}/transition`),
					buildTransitionPayload({
						transitionId: this.transition?.id,
						comment: this.comment,
						resultTypeId: this.result?.id ?? '',
					}),
				)
				// The strip, the stepper and the record itself all re-read on
				// this signal, so one transition moves the whole page.
				emit(PAGE_REFRESH, {})
				this.$emit('close')
			} catch (error) {
				this.error = refusalMessage(error?.response?.data ?? {}, (s) => t('dossiq', s))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.case-transition-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 16px 16px;
}

.case-transition-dialog__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
