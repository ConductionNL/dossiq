<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	One contact moment, read-only, opened from a row of the contact and
	organisation pages' Contact moments panel (REQ-CRS-04).

	There is no contact-moment page, and the agent opening it usually has the
	contact on the line, so the moment opens over the list rather than away
	from it. Only what the moment carries is shown: a field the reader may not
	see arrives empty from OpenRegister and is left out, never drawn blank.

	@spec openspec/changes/capability-rows-say-the-whole-capability/specs/party-pages/spec.md#requirement-the-contact-page-lists-the-contacts-documents-and-communication-req-crs-04
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Contact moment')"
		data-testid="contact-moment-detail-dialog"
		@closing="$emit('close')">
		<dl v-if="facts.length > 0" class="contact-moment-detail">
			<template v-for="fact in facts" :key="fact.key">
				<dt>
					{{ fact.label }}
				</dt>
				<dd
					:data-testid="'contact-moment-detail-' + fact.key"
					class="contact-moment-detail__value">
					{{ fact.value }}
				</dd>
			</template>
		</dl>
		<p v-else data-testid="contact-moment-detail-empty">
			{{ t('dossiq', 'This contact moment holds nothing you may read.') }}
		</p>

		<template #actions>
			<NcButton
				v-if="caseUrl !== ''"
				:href="caseUrl"
				data-testid="contact-moment-detail-case">
				{{ t('dossiq', 'Open the case') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="contact-moment-detail-close"
				@click="$emit('close')">
				{{ t('dossiq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'

/**
 * The channel codes the contactmoment schema stores, in words.
 *
 * @return {object}
 */
function channelLabels() {
	return {
		phone: t('dossiq', 'Phone'),
		email: t('dossiq', 'Email'),
		webformulier: t('dossiq', 'Web form'),
		chat: t('dossiq', 'Chat'),
		social_media: t('dossiq', 'Social media'),
		balie: t('dossiq', 'Desk'),
		brief: t('dossiq', 'Letter'),
	}
}

/**
 * The direction codes the contactmoment schema stores, in words.
 *
 * @return {object}
 */
function directionLabels() {
	return {
		inbound: t('dossiq', 'Inbound'),
		outbound: t('dossiq', 'Outbound'),
	}
}

/**
 * A stored date-time in the reader's locale, or the stored text when it does
 * not parse, so a malformed value is still shown as what was recorded.
 *
 * @param {string} value An ISO 8601 date-time.
 * @return {string}
 */
function formatMoment(value) {
	const date = new Date(value)
	if (Number.isNaN(date.getTime())) {
		return String(value)
	}
	return date.toLocaleString()
}

export default {
	name: 'ContactMomentDetailDialog',

	components: { NcButton, NcDialog },

	props: {
		/** The contact moment, as the record list hands its row to a dialog. */
		row: {
			type: Object,
			default: null,
		},
	},

	emits: ['close'],

	computed: {
		/**
		 * The moment's facts in reading order, without the empty ones.
		 *
		 * @return {Array<{key: string, label: string, value: string}>}
		 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/party-pages/spec.md#requirement-the-contact-page-lists-the-contacts-documents-and-communication-req-crs-04
		 */
		facts() {
			const moment = this.row || {}
			const channels = channelLabels()
			const directions = directionLabels()
			const facts = [
				{ key: 'startTime', label: t('dossiq', 'Start time'), value: moment.startTime ? formatMoment(moment.startTime) : '' },
				{ key: 'endTime', label: t('dossiq', 'End time'), value: moment.endTime ? formatMoment(moment.endTime) : '' },
				{ key: 'channel', label: t('dossiq', 'Channel'), value: channels[moment.notificationChannel] || moment.notificationChannel || '' },
				{ key: 'direction', label: t('dossiq', 'Direction'), value: directions[moment.direction] || moment.direction || '' },
				{ key: 'subject', label: t('dossiq', 'Subject'), value: moment.subject || '' },
				{ key: 'summary', label: t('dossiq', 'Summary'), value: moment.summary || '' },
				{ key: 'message', label: t('dossiq', 'Message'), value: moment.message || '' },
				{ key: 'note', label: t('dossiq', 'Note'), value: moment.note || '' },
			]
			return facts.filter((fact) => String(fact.value).trim() !== '')
		},

		/**
		 * The page of the case the moment is about, or the empty string.
		 *
		 * @return {string}
		 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/party-pages/spec.md#requirement-the-contact-page-lists-the-contacts-documents-and-communication-req-crs-04
		 */
		caseUrl() {
			const value = this.row && this.row.case
			let caseId = ''
			if (typeof value === 'string') {
				caseId = value
			} else if (value !== null && typeof value === 'object') {
				caseId = String(value.id || value['@self']?.id || '')
			}
			if (caseId === '') {
				return ''
			}
			return generateUrl(`/apps/dossiq/cases/${encodeURIComponent(caseId)}`)
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.contact-moment-detail {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 4);
	margin: 0;
}

.contact-moment-detail dt {
	color: var(--color-text-maxcontrast);
}

.contact-moment-detail__value {
	margin: 0;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}
</style>
