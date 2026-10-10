<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Log contact, the case page header action (board DqZaakContactmomenten).

	🔴 WHY A DIALOG AND NOT THE MANIFEST FORM IT REPLACES. The `open-form`
	action saved straight to OpenRegister's object API, so no dossiq service
	ran: the moment never reached pipelinq's customer record, and when pipelinq
	refuses one (an indicator that blocks outbound contact) nobody could have
	told the handler. This posts to dossiq's case route, where
	ContactMomentService writes the dossiq record FIRST and then appends it to
	pipelinq, and the answer carries pipelinq's refusal back.

	🔴 A REFUSAL DOES NOT CLOSE IT, AND DOES NOT UNDO THE SAVE. The dossiq
	record is kept whatever pipelinq says; the dialog switches to a summary that
	says the moment is on the case AND that pipelinq did not take it, naming the
	indicator. A handler who closes it knows both.

	The fields are the ones the form asked: channel, direction, moment, summary,
	who called, visible to the applicant. The KCC fields the form never asked
	(identification method, nature, employee) are filled by the service.

	@spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Log contact')"
		data-testid="log-contact-dialog"
		@closing="$emit('close')">
		<div v-if="saved === null" class="log-contact">
			<fieldset class="log-contact__group">
				<legend>{{ t('dossiq', 'Channel') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="option in channels"
					:key="option.value"
					type="radio"
					name="log-contact-channel"
					:modelValue="channel"
					:value="option.value"
					:data-testid="'log-contact-channel-' + option.value"
					@update:modelValue="channel = $event">
					{{ option.label }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<fieldset class="log-contact__group">
				<legend>{{ t('dossiq', 'Direction') }}</legend>
				<NcCheckboxRadioSwitch
					type="radio"
					name="log-contact-direction"
					:modelValue="direction"
					value="inbound"
					data-testid="log-contact-direction-inbound"
					@update:modelValue="direction = $event">
					{{ t('dossiq', 'Inbound') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					type="radio"
					name="log-contact-direction"
					:modelValue="direction"
					value="outbound"
					data-testid="log-contact-direction-outbound"
					@update:modelValue="direction = $event">
					{{ t('dossiq', 'Outbound') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<NcDateTimePicker
				v-model="startTime"
				data-testid="log-contact-start"
				type="datetime"
				:label="t('dossiq', 'Moment')" />

			<NcTextArea
				v-model="summary"
				data-testid="log-contact-summary"
				:label="t('dossiq', 'Summary')" />

			<NcTextField
				v-model="callerIdentification"
				data-testid="log-contact-caller"
				:label="t('dossiq', 'Who called or was called')" />

			<NcCheckboxRadioSwitch
				:modelValue="visibleToApplicant"
				data-testid="log-contact-visible"
				@update:modelValue="visibleToApplicant = $event">
				{{ t('dossiq', 'Visible to the applicant') }}
			</NcCheckboxRadioSwitch>

			<p
				v-if="error"
				class="log-contact__error"
				role="alert"
				data-testid="log-contact-error">
				{{ error }}
			</p>
		</div>

		<div v-else class="log-contact">
			<p
				class="log-contact__saved"
				role="status"
				data-testid="log-contact-saved">
				{{ t('dossiq', 'The contact moment is on this case.') }}
			</p>
			<div
				v-if="refusal"
				class="log-contact__refusal"
				role="alert"
				data-testid="log-contact-refusal">
				<strong>{{
					t('dossiq', 'Pipelinq did not put it in the customer record')
				}}</strong>
				<span>{{ refusal }}</span>
			</div>
			<dl class="log-contact__summary">
				<dt>{{ t('dossiq', 'Channel') }}</dt>
				<dd>{{ channelLabel }}</dd>
				<dt>{{ t('dossiq', 'Direction') }}</dt>
				<dd>
					{{
						direction === 'outbound'
							? t('dossiq', 'Outbound')
							: t('dossiq', 'Inbound')
					}}
				</dd>
				<dt>{{ t('dossiq', 'Summary') }}</dt>
				<dd>{{ summary }}</dd>
			</dl>
		</div>

		<template #actions>
			<template v-if="saved === null">
				<NcButton data-testid="log-contact-cancel" @click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="log-contact-confirm"
					:disabled="!canSave"
					@click="save">
					{{ t('dossiq', 'Save') }}
				</NcButton>
			</template>
			<NcButton
				v-else
				variant="primary"
				data-testid="log-contact-close"
				@click="$emit('close')">
				{{ t('dossiq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePicker from '@nextcloud/vue/components/NcDateTimePicker'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import {
	logContactMoment,
	refusalOf,
	refusalSentence,
} from '../services/pipelinqCaseApi.js'

export default {
	name: 'LogContactDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDateTimePicker,
		NcDialog,
		NcTextArea,
		NcTextField,
	},

	props: {
		/**
		 * The case. Arrives as the unresolved `@objectId` token from an
		 * `open-modal` action, so the route answers instead.
		 */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			channel: 'phone',
			direction: 'inbound',
			startTime: new Date(),
			summary: '',
			callerIdentification: '',
			visibleToApplicant: false,
			busy: false,
			error: '',
			saved: null,
			refusal: '',
		}
	},

	computed: {
		/** @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02 */
		targetCaseId() {
			const given = String(this.caseId || '')
			if (given !== '' && given.startsWith('@') === false) {
				return given
			}

			return String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02 */
		channels() {
			return [
				{ value: 'phone', label: t('dossiq', 'Phone') },
				{ value: 'email', label: t('dossiq', 'Email') },
				{ value: 'balie', label: t('dossiq', 'Desk') },
				{ value: 'webformulier', label: t('dossiq', 'Web form') },
				{ value: 'chat', label: t('dossiq', 'Chat') },
				{ value: 'social_media', label: t('dossiq', 'Social media') },
			]
		},

		/** @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02 */
		channelLabel() {
			return (
				this.channels.find((option) => option.value === this.channel)?.label
				|| this.channel
			)
		},

		/** @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02 */
		canSave() {
			return (
				this.busy === false
				&& this.targetCaseId !== ''
				&& this.summary.trim() !== ''
			)
		},
	},

	methods: {
		t,

		/**
		 * Save the moment on this case and show what pipelinq said.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02
		 */
		async save() {
			this.busy = true
			this.error = ''
			try {
				const start =
					this.startTime instanceof Date
						? this.startTime.toISOString()
						: ''
				const answer = await logContactMoment(this.targetCaseId, {
					notificationChannel: this.channel,
					direction: this.direction,
					startTime: start,
					summary: this.summary.trim(),
					callerIdentification: this.callerIdentification.trim(),
					visibleToApplicant: this.visibleToApplicant,
				})
				this.saved = answer?.contactmoment || {}
				this.refusal = refusalSentence(
					answer?.pipelinqRefusal,
					answer?.pipelinqIndicators,
				)
			} catch (error) {
				this.error = refusalOf(
					error,
					t('dossiq', 'The contact moment could not be saved.'),
				)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.log-contact {
	display: flex;
	flex-direction: column;
	gap: 12px;

	&__group {
		border: 0;
		margin: 0;
		padding: 0;
		display: flex;
		flex-wrap: wrap;
		gap: 4px 12px;

		legend {
			font-weight: 600;
			margin-bottom: 4px;
		}
	}

	&__saved {
		margin: 0;
		padding: 12px 14px;
		border-radius: var(--border-radius-large);
		background: var(--color-success);
		color: var(--color-success-text, var(--color-primary-element-text));
	}

	&__refusal {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 12px 14px;
		border-radius: var(--border-radius-large);
		background: var(--color-warning);
		color: var(--color-warning-text, var(--color-main-text));
	}

	&__summary {
		margin: 0;
		display: grid;
		grid-template-columns: max-content 1fr;
		gap: 6px 16px;

		dt {
			color: var(--color-text-maxcontrast);
		}

		dd {
			margin: 0;
		}
	}

	&__error {
		margin: 0;
		color: var(--color-error-text);
	}
}
</style>
