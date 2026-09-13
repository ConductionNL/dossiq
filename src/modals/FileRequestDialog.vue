<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcModal size="normal" @close="$emit('close')">
		<div class="file-request-dialog">
			<h2 class="file-request-dialog__title">
				{{ t('dossiq', 'Request a file from a party') }}
			</h2>

			<p class="file-request-dialog__lead">
				{{
					t(
						'dossiq',
						'The party gets a link that only uploads into this case folder. What they send lands in the case as a document.',
					)
				}}
			</p>

			<NcLoadingIcon v-if="loading" class="file-request-dialog__loading" />

			<p
				v-else-if="parties.length === 0"
				class="file-request-dialog__empty"
				data-testid="file-request-empty">
				{{
					t(
						'dossiq',
						'Nobody is linked to this case yet. Add a party on the People tab first.',
					)
				}}
			</p>

			<ul v-else class="file-request-dialog__parties">
				<li
					v-for="party in parties"
					:key="party.id"
					class="file-request-dialog__party"
					:class="{
						'file-request-dialog__party--disabled': !party.canBeAsked,
					}"
					:data-testid="
						party.canBeAsked
							? 'file-request-party'
							: 'file-request-party-unavailable'
					"
					:data-party="party.id">
					<NcCheckboxRadioSwitch
						type="radio"
						name="file-request-party"
						:disabled="!party.canBeAsked"
						:modelValue="selected"
						:value="party.id"
						@update:modelValue="selected = $event">
						{{ party.name }}
					</NcCheckboxRadioSwitch>
					<span
						v-if="party.canBeAsked"
						class="file-request-dialog__party-email"
						>{{ party.email }}</span
					>
					<span v-else class="file-request-dialog__party-reason">
						{{
							t(
								'dossiq',
								'No email address, so this party cannot be asked',
							)
						}}
					</span>
				</li>
			</ul>

			<NcTextField
				v-model="note"
				:label="t('dossiq', 'What do you need from them?')"
				:placeholder="t('dossiq', 'For example: a copy of the lease')"
				data-testid="file-request-note" />

			<NcTextField
				v-model="days"
				type="number"
				:label="t('dossiq', 'Days the request stands')"
				data-testid="file-request-days" />

			<p
				v-if="error"
				class="file-request-dialog__error"
				data-testid="file-request-error">
				{{ error }}
			</p>

			<div class="file-request-dialog__actions">
				<NcButton @click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="selected === '' || sending"
					data-testid="file-request-send"
					@click="send">
					{{ t('dossiq', 'Send the request') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcModal,
	NcTextField,
} from '@nextcloud/vue'

/**
 * Ask a party of the case for a file.
 *
 * Opened from the Files tab's New menu as a manifest `open-modal` action, so
 * the registry mounts it with the action's props and nothing else: a mounted
 * dialog is an open one, and it reads the case from its prop or the route.
 *
 * The recipients are the people linked to the case, because "who do I send
 * this to" was the question Nextcloud's own file request could not answer. A
 * party with no address is listed and disabled with the reason rather than
 * hidden, so the gap is visible and fixable on the People tab.
 *
 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
 */
export default {
	name: 'FileRequestDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		NcTextField,
	},

	props: {
		/**
		 * The case the file is requested for. Falls back to the route when the
		 * manifest's `@objectId` token reaches the dialog unresolved.
		 */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			parties: [],
			selected: '',
			note: '',
			days: 14,
			loading: true,
			sending: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The case uuid: the prop when it holds one, else the route's.
		 *
		 * @return {string} The uuid.
		 *
		 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
		 */
		resolvedCaseId() {
			const fromProp = this.caseId || ''
			if (fromProp !== '' && fromProp.startsWith('@') === false) {
				return fromProp
			}

			return this.$route?.params?.id || this.$route?.params?.objectId || ''
		},
	},

	created() {
		this.loadParties()
	},

	methods: {
		t,

		/**
		 * The people on the case, each with whether they can be asked.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
		 */
		async loadParties() {
			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/dossiq/api/cases/${this.resolvedCaseId}/file-requests/parties`,
					),
				)
				this.parties = Array.isArray(data?.parties) ? data.parties : []
				const first = this.parties.find((party) => party.canBeAsked)
				this.selected = first ? first.id : ''
			} catch {
				this.parties = []
				this.error = t(
					'dossiq',
					'The parties of this case could not be read',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Send the request to the selected party.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
		 */
		async send() {
			if (this.selected === '') {
				return
			}
			this.sending = true
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl(
						`/apps/dossiq/api/cases/${this.resolvedCaseId}/file-requests`,
					),
					{
						personId: this.selected,
						note: this.note,
						days: Number(this.days) || 0,
					},
				)
				showSuccess(
					t('dossiq', 'The request is on its way to {recipient}', {
						recipient: data?.recipient ?? '',
					}),
				)
				this.$emit('close')
			} catch (e) {
				const message = e?.response?.data?.error
				this.error = message || t('dossiq', 'The request could not be sent')
				showError(this.error)
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.file-request-dialog {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 4);
}

.file-request-dialog__title {
	margin: 0;
}

.file-request-dialog__lead,
.file-request-dialog__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.file-request-dialog__parties {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 1);
	margin: 0;
	padding: 0;
	list-style: none;
}

.file-request-dialog__party {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	flex-wrap: wrap;
}

.file-request-dialog__party--disabled {
	opacity: 0.7;
}

.file-request-dialog__party-email,
.file-request-dialog__party-reason {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.file-request-dialog__error {
	margin: 0;
	color: var(--color-error);
}

.file-request-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
