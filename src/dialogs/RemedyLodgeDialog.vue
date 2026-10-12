<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Lodge a remedy against the decision of this case, a case page header action.

	GENERIC (decision 182). Nothing here names a procedure. The case type says
	which remedy is open against its decisions and which case type that opens
	(`remedy.caseType`); the server reads that. The manifest action carries the
	rest in `props`: where to post, the channels a lodging can arrive through,
	and which page opens the new case.

	THE SERVER DECIDES. Whether the case has a decision, whether its case type
	offers a remedy and whether the lodging is on time are all answered by the
	route. A refusal is shown with the server's sentence and the dialog stays
	open. A late remedy IS registered (Awb art. 6:11), so that answer is shown
	before the handler moves on, not hidden behind a navigation.

	@spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-from-the-case-page-req-rml-003
-->
<template>
	<NcDialog
		:name="heading"
		data-testid="remedy-lodge-dialog"
		@closing="$emit('close')">
		<div v-if="lodged === null" class="remedy-lodge">
			<NcDateTimePicker
				v-model="receivedDate"
				data-testid="remedy-lodge-received"
				type="date"
				:max="today"
				:label="t('dossiq', 'Received on')" />

			<NcSelect
				v-model="channel"
				data-testid="remedy-lodge-channel"
				:options="channelOptions"
				:inputLabel="t('dossiq', 'Received through')"
				:clearable="false" />

			<NcTextArea
				v-model="grounds"
				data-testid="remedy-lodge-grounds"
				:label="t('dossiq', 'Grounds')" />

			<NcTextArea
				v-model="requestedRelief"
				data-testid="remedy-lodge-relief"
				:label="t('dossiq', 'What is asked for (optional)')" />

			<p
				v-if="error"
				class="remedy-lodge__error"
				role="alert"
				data-testid="remedy-lodge-error">
				{{ error }}
			</p>
		</div>

		<div v-else class="remedy-lodge">
			<p role="status" data-testid="remedy-lodge-done">
				{{ t('dossiq', 'It is registered and has its own case.') }}
			</p>
			<p
				v-if="lodged.isTimely === false"
				class="remedy-lodge__late"
				role="alert"
				data-testid="remedy-lodge-late">
				{{
					t(
						'dossiq',
						'It was received after the term ended. It is registered anyway, marked as late, for the jurist to judge.',
					)
				}}
			</p>
			<p v-if="lodged.deadline" data-testid="remedy-lodge-deadline">
				{{
					t('dossiq', 'The term of the new case ends on {date}.', {
						date: deadlineDay,
					})
				}}
			</p>
		</div>

		<template #actions>
			<template v-if="lodged === null">
				<NcButton data-testid="remedy-lodge-cancel" @click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="remedy-lodge-save"
					:disabled="!canSave"
					@click="save">
					{{ t('dossiq', 'Save') }}
				</NcButton>
			</template>
			<template v-else>
				<NcButton data-testid="remedy-lodge-close" @click="$emit('close')">
					{{ t('dossiq', 'Stay on this case') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="remedy-lodge-open"
					@click="open">
					{{ t('dossiq', 'Open the new case') }}
				</NcButton>
			</template>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePicker from '@nextcloud/vue/components/NcDateTimePicker'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'

const PAGE_REFRESH = 'cn:page:refresh'

/**
 * A date as the local calendar day, `YYYY-MM-DD`.
 *
 * Not `toISOString()`: that is UTC, and a letter received today, entered just
 * after midnight, would be sent as yesterday and judged against the wrong day.
 *
 * @param {Date} date The picked date.
 * @return {string} The day, or '' when there is none.
 */
function localDay(date) {
	if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
		return ''
	}
	const pad = (value) => String(value).padStart(2, '0')

	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

export default {
	name: 'RemedyLodgeDialog',

	components: {
		NcButton,
		NcDateTimePicker,
		NcDialog,
		NcSelect,
		NcTextArea,
	},

	props: {
		/**
		 * The decided case. Arrives as the unresolved `@objectId` token from
		 * an `open-modal` action, so the route answers instead.
		 */
		caseId: {
			type: String,
			default: '',
		},

		/** What the dialog is called, in the words of the case type that configures it. */
		title: {
			type: String,
			default: 'Lodge a remedy',
		},

		/** Where the lodging is posted; `{caseId}` is filled in. */
		lodgeUrl: {
			type: String,
			default: '/apps/dossiq/api/cases/{caseId}/remedy',
		},

		/** The channels a lodging can arrive through: `{ value, label }`. */
		channels: {
			type: Array,
			default: () => [],
		},

		/** The page that opens the new case, and the answer field that holds its id. */
		openRoute: {
			type: Object,
			default: () => ({ name: 'CaseDetail', idField: 'caseId' }),
		},
	},

	emits: ['close'],

	data() {
		return {
			receivedDate: new Date(),
			today: new Date(),
			channel: null,
			grounds: '',
			requestedRelief: '',
			busy: false,
			error: '',
			lodged: null,
		}
	},

	computed: {
		/** @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-from-the-case-page-req-rml-003 */
		targetCaseId() {
			const given = String(this.caseId || '')
			if (given !== '' && given.startsWith('@') === false) {
				return given
			}

			return String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-from-the-case-page-req-rml-003 */
		heading() {
			return t('dossiq', this.title)
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-from-the-case-page-req-rml-003 */
		channelOptions() {
			return this.channels.map((option) => ({
				id: String(option?.value ?? ''),
				label: t('dossiq', String(option?.label ?? option?.value ?? '')),
			}))
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-from-the-case-page-req-rml-003 */
		deadlineDay() {
			const date = new Date(String(this.lodged?.deadline || ''))
			return Number.isNaN(date.getTime())
				? String(this.lodged?.deadline || '')
				: date.toLocaleDateString()
		},

		/** @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-from-the-case-page-req-rml-003 */
		canSave() {
			return (
				this.busy === false
				&& this.targetCaseId !== ''
				&& localDay(this.receivedDate) !== ''
				&& this.channel !== null
				&& this.grounds.trim() !== ''
			)
		},
	},

	methods: {
		t,

		/**
		 * Post the lodging once and show what the server answered.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-from-the-case-page-req-rml-003
		 */
		async save() {
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl(
						String(this.lodgeUrl).replace(
							'{caseId}',
							encodeURIComponent(this.targetCaseId),
						),
					),
					{
						receivedDate: localDay(this.receivedDate),
						receivedChannel: this.channel.id,
						grounds: this.grounds.trim(),
						requestedRelief: this.requestedRelief.trim(),
					},
				)
				this.lodged = data || {}
				emit(PAGE_REFRESH, {})
			} catch (failure) {
				const data = failure?.response?.data
				this.error = String(
					data?.message
						|| data?.error
						|| t('dossiq', 'This could not be registered.'),
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Go to the case the lodging opened.
		 *
		 * @return {void}
		 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-from-the-case-page-req-rml-003
		 */
		open() {
			const id = String(
				this.lodged?.[this.openRoute?.idField || 'caseId'] ?? '',
			)
			if (id !== '') {
				this.$router.push({
					name: this.openRoute?.name || 'CaseDetail',
					params: { id },
				})
			}
			this.$emit('close')
		},
	},
}
</script>

<style scoped lang="scss">
.remedy-lodge {
	display: flex;
	flex-direction: column;
	gap: 12px;

	p {
		margin: 0;
	}

	&__late {
		padding: 12px 14px;
		border-radius: var(--border-radius-large);
		background: var(--color-warning);
		color: var(--color-warning-text, var(--color-main-text));
	}

	&__error {
		color: var(--color-error-text);
	}
}
</style>
