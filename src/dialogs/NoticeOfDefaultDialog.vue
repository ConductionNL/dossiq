<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Register a notice of default, the case page header action (boards DqZaak
	and DqTermijnen). The handler records the day the notice came in, how and
	who sent it. The server looks up the case's statutory decision term, refuses
	a notice while that term still runs (and stores nothing), and otherwise
	starts the accruing amount. Every verdict is the server's; the dialog only
	says it.

	@spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
-->
<template>
	<CnDialog
		:name="t('dossiq', 'Register notice of default')"
		data-testid="notice-of-default-dialog"
		@closing="$emit('close')">
		<div v-if="saved === null" class="notice-of-default">
			<NcDateTimePicker
				v-model="receiptDate"
				data-testid="notice-of-default-received"
				type="date"
				:label="t('dossiq', 'Received on')" />

			<NcSelect
				v-model="channel"
				data-testid="notice-of-default-channel"
				:inputLabel="t('dossiq', 'Received through')"
				:options="channels"
				:reduce="(option) => option.value"
				:clearable="false"
				label="label" />

			<NcSelect
				v-model="sender"
				data-testid="notice-of-default-sender"
				:inputLabel="t('dossiq', 'Sent by')"
				:placeholder="t('dossiq', 'Choose a party or type a name')"
				:options="parties"
				:loading="loadingParties"
				taggable />

			<p
				v-if="error"
				class="notice-of-default__error"
				role="alert"
				data-testid="notice-of-default-error">
				{{ error }}
			</p>
		</div>

		<p
			v-else
			class="notice-of-default__saved"
			role="status"
			data-testid="notice-of-default-saved">
			{{ savedSentence }}
		</p>

		<template #actions>
			<template v-if="saved === null">
				<NcButton
					data-testid="notice-of-default-cancel"
					@click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="notice-of-default-confirm"
					:disabled="!canSave"
					@click="save">
					{{ t('dossiq', 'Record the notice') }}
				</NcButton>
			</template>
			<NcButton
				v-else
				variant="primary"
				data-testid="notice-of-default-close"
				@click="$emit('close')">
				{{ t('dossiq', 'Close') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePicker from '@nextcloud/vue/components/NcDateTimePicker'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { noticeRefusal, registerNotice } from '../services/noticeOfDefaultApi.js'

/**
 * A date as the calendar day the reader picked, not its UTC instant.
 *
 * @param {Date} date The picked date.
 * @return {string} YYYY-MM-DD.
 */
function calendarDay(date) {
	const pad = (n) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

export default {
	name: 'NoticeOfDefaultDialog',

	components: {
		CnDialog,
		NcButton,
		NcDateTimePicker,
		NcSelect,
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
			receiptDate: new Date(),
			channel: 'post',
			sender: '',
			parties: [],
			loadingParties: false,
			busy: false,
			error: '',
			saved: null,
		}
	},

	computed: {
		/** @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01 */
		targetCaseId() {
			const given = String(this.caseId || '')
			if (given !== '' && given.startsWith('@') === false) {
				return given
			}

			return String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01 */
		channels() {
			return [
				{ value: 'post', label: t('dossiq', 'Letter') },
				{ value: 'email', label: t('dossiq', 'Email') },
				{ value: 'portal', label: t('dossiq', 'Portal') },
				{ value: 'balie', label: t('dossiq', 'Desk') },
				{ value: 'phone', label: t('dossiq', 'Phone') },
			]
		},

		/** @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01 */
		canSave() {
			return (
				this.busy === false
				&& this.targetCaseId !== ''
				&& this.receiptDate instanceof Date
				&& this.channel !== ''
			)
		},

		/** @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01 */
		savedSentence() {
			const start = this.saved?.penaltyPaymentCalculation?.startDate
			if (start) {
				return t(
					'dossiq',
					'The notice of default is on this case. The penalty starts running on {date}.',
					{ date: start },
				)
			}

			return t(
				'dossiq',
				'The notice of default is on this case. An earlier notice already started the penalty.',
			)
		},
	},

	mounted() {
		this.loadParties()
	},

	methods: {
		t,

		/**
		 * Read the case's parties, offered as who sent the notice.
		 *
		 * A failed read leaves the list empty; a name can still be typed.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
		 */
		async loadParties() {
			if (this.targetCaseId === '') {
				return
			}

			this.loadingParties = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/objects/dossiq/role'),
					{ params: { case: this.targetCaseId, _limit: 100 } },
				)
				const names = (data?.results || [])
					.map((row) => String(row?.displayName || row?.name || '').trim())
					.filter((name) => name !== '')
				this.parties = [...new Set(names)]
			} catch {
				this.parties = []
			} finally {
				this.loadingParties = false
			}
		},

		/**
		 * Register the notice and show what the server decided.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
		 */
		async save() {
			if (!this.canSave) {
				return
			}

			this.busy = true
			this.error = ''
			try {
				this.saved = await registerNotice(this.targetCaseId, {
					receiptDate: calendarDay(this.receiptDate),
					notificationChannel: this.channel,
					sender: String(this.sender || '').trim(),
				})
			} catch (error) {
				this.error = noticeRefusal(
					error,
					t('dossiq', 'The notice of default could not be registered.'),
				)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.notice-of-default {
	display: flex;
	flex-direction: column;
	gap: 12px;

	&__error {
		margin: 0;
		color: var(--color-error-text, var(--color-error));
	}

	&__saved {
		margin: 0;
		padding: 12px 14px;
		border-radius: var(--border-radius-large);
		background: var(--color-success);
		color: var(--color-success-text, var(--color-primary-element-text));
	}
}
</style>
