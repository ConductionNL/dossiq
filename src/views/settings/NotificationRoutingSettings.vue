<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Which notices reach you, and which layer decided that.

  THE DECIDING LAYER IS THE FEATURE. A switch whose effect depends on a layer
  the screen does not show is a switch people stop trusting: they turn it off,
  keep getting the notice, and conclude the setting is broken. So every value
  says who set it, and a value a team default decided says so in words rather
  than just reading as off.

  THIS IS NOW THE SHARED SCREEN. dossiq had its own list beside
  CnNotificationMatrix, which does the same job with one layer more. The
  hand-rolled list could say app default, team default and your own, and had
  nowhere to put a channel an administrator has FORCED or one the platform
  REFUSES for this recipient. A handler could therefore see a switch that was
  on, believe they had turned it off, and keep receiving the notice with
  nothing on the page saying an administrator had overridden them. That is the
  whole reason the shared screen exists, so this consumes it rather than
  growing a second implementation towards it.

  What is kept from the old screen: the domain selector, because pinning a
  preference to one part of your work is dossiq's own idea and the shared
  screen takes a scope per row; the team default block, because setting one is
  an act of administration the platform checks and refuses with a 403 that is
  shown rather than second-guessed; and the labels, because a handler should
  read "a case is assigned to me", not a schema key.

  What is gone: the per-row "use the setting from my team" button. The shared
  screen has no such control, so clearing is what a handler does by switching a
  row back to what the layer below says, and the row then names that layer.

  @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
-->
<template>
	<div class="notification-routing">
		<div class="notification-routing__scope">
			<NcSelect
				:modelValue="selectedDomain"
				:options="domainOptions"
				:aria-label-combobox="t('dossiq', 'Notification domain')"
				:inputLabel="t('dossiq', 'Show the settings as they apply to')"
				data-testid="notification-routing-scope"
				@update:modelValue="chooseDomain" />
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="entries.length === 0"
			:name="t('dossiq', 'No notifications to set')"
			:description="
				t('dossiq', 'This instance routes no dossiq notifications yet.')
			" />

		<CnNotificationMatrix
			v-else
			v-bind="preferenceProps"
			data-testid="notification-routing-preferences"
			@change="onChange" />

		<p
			v-if="message"
			class="notification-routing__message"
			data-testid="notification-routing-message">
			{{ message }}
		</p>

		<div v-if="isAdmin" class="notification-routing__group">
			<h3>{{ t('dossiq', 'Set a default for a team') }}</h3>
			<p class="notification-routing__explainer">
				{{
					t(
						'dossiq',
						'A team default decides for everyone in the group who has not chosen for themselves.',
					)
				}}
			</p>

			<NcTextField
				:modelValue="group"
				:label="t('dossiq', 'Group')"
				data-testid="notification-routing-group"
				@update:modelValue="(v) => (group = v)" />

			<NcSelect
				:modelValue="selectedGroupEntry"
				:options="entryOptions"
				label="notificationLabel"
				:aria-label-combobox="t('dossiq', 'Notification')"
				:inputLabel="t('dossiq', 'Notification')"
				data-testid="notification-routing-group-notification"
				@update:modelValue="(v) => (selectedGroupEntry = v)" />

			<div class="notification-routing__group-actions">
				<NcButton
					:disabled="!canWriteGroupDefault"
					data-testid="notification-routing-group-on"
					@click="setGroupDefault(true)">
					{{ t('dossiq', 'On for the team') }}
				</NcButton>
				<NcButton
					:disabled="!canWriteGroupDefault"
					data-testid="notification-routing-group-off"
					@click="setGroupDefault(false)">
					{{ t('dossiq', 'Off for the team') }}
				</NcButton>
			</div>

			<p
				v-if="groupMessage"
				class="notification-routing__message"
				data-testid="notification-routing-group-message">
				{{ groupMessage }}
			</p>
		</div>
	</div>
</template>

<script>
import { CnNotificationMatrix } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import {
	keyFor,
	propsFor,
	writeFor,
} from '../../services/notificationPreferenceProps.js'
import {
	fetchPreferences,
	saveGroupDefault,
	savePreference,
} from '../../services/notificationRoutingApi.js'

export default {
	name: 'NotificationRoutingSettings',

	components: {
		CnNotificationMatrix,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},

	props: {
		isAdmin: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			entries: [],
			channels: [],
			loading: true,
			domain: '',
			group: '',
			selectedGroupEntry: null,
			groupMessage: '',
			message: '',
		}
	},

	computed: {
		/**
		 * What the shared screen renders.
		 *
		 * @return {object} Its props.
		 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
		 */
		preferenceProps() {
			return propsFor({
				entries: this.entries,
				channels: this.channels,
				label: (entry) => this.labelFor(entry),
				singleChannelLabel: t('dossiq', 'Notifications'),
			})
		},

		/**
		 * The notifications a team default can be set on.
		 *
		 * @return {Array<object>} The entries, each carrying its own label.
		 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
		 */
		entryOptions() {
			return this.entries.map((entry) => ({
				...entry,
				notificationLabel: this.labelFor(entry),
			}))
		},

		/**
		 * The domains a preference may be pinned to, plus the unpinned answer.
		 *
		 * @return {Array<{id: string, label: string}>} The options.
		 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
		 */
		domainOptions() {
			return [
				{ id: '', label: t('dossiq', 'All my work') },
				{ id: 'zaken', label: t('dossiq', 'Cases') },
				{ id: 'waarneming', label: t('dossiq', 'Substitution') },
				{ id: 'werkvoorraad', label: t('dossiq', 'My work queue') },
			]
		},

		/**
		 * The domain currently being shown.
		 *
		 * @return {{id: string, label: string}} The option.
		 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
		 */
		selectedDomain() {
			return (
				this.domainOptions.find((o) => o.id === this.domain)
				|| this.domainOptions[0]
			)
		},

		/**
		 * Whether a team default can be written from what is filled in.
		 *
		 * @return {boolean} TRUE when a group and a notification are chosen.
		 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
		 */
		canWriteGroupDefault() {
			return this.group.trim() !== '' && this.selectedGroupEntry !== null
		},
	},

	/**
	 * Read the reader's own effective preferences.
	 *
	 * @return {Promise<void>} When the read has finished.
	 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		t,
		keyFor,

		/**
		 * Read the preferences as they apply in the chosen domain.
		 *
		 * @return {Promise<void>} When the read has finished.
		 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
		 */
		async load() {
			this.loading = true
			try {
				const scope = this.domain ? `domain:${this.domain}` : null
				const answer = await fetchPreferences(scope)

				// The service still answers with a bare array on an instance
				// whose platform has no channel axis. Both shapes are read
				// rather than one being assumed, because assuming the newer
				// one renders an empty screen on every instance that has not
				// been upgraded yet.
				this.entries = Array.isArray(answer) ? answer : (answer?.entries || [])
				this.channels = Array.isArray(answer) ? [] : (answer?.channels || [])
			} finally {
				this.loading = false
			}
		},

		/**
		 * What to call this notification on screen.
		 *
		 * @param {object} entry The entry.
		 * @return {string} The label.
		 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
		 */
		labelFor(entry) {
			const known = {
				caseAssigned: t('dossiq', 'A case is assigned to me'),
				caseHandoffIntake: t(
					'dossiq',
					'A case reaches us through a handoff',
				),

				substitutionRegisteredForSubstitute: t(
					'dossiq',
					'I am registered to stand in for someone',
				),

				workDigestReady: t('dossiq', 'My daily work digest'),
			}

			return (
				known[entry.notification]
				|| `${entry.schemaTitle || entry.schema}: ${entry.notification}`
			)
		},

		/**
		 * Show the preferences as they apply in one domain.
		 *
		 * @param {?{id: string}} option The chosen domain.
		 * @return {Promise<void>} When the read has finished.
		 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
		 */
		async chooseDomain(option) {
			this.domain = option ? option.id : ''
			await this.load()
		},

		/**
		 * Record the reader's own value for one cell.
		 *
		 * The shared screen never emits for a locked cell, so a forced row
		 * cannot be written from here. The guard below is not that check: it
		 * refuses an id this app did not make, because a write to the wrong
		 * notification is silent and permanent.
		 *
		 * @param {object} change What was set.
		 * @param {string} change.eventId The row's id.
		 * @param {string} [change.scope] The scope the row was on.
		 * @param {boolean} change.value The new value.
		 * @return {Promise<void>} When the write has finished.
		 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
		 */
		async onChange({ eventId, scope = '', value }) {
			const write = writeFor({ eventId, scope: scope || this.domain, value })
			if (write === null) {
				return
			}

			this.message = ''
			try {
				await savePreference({
					schema: write.schema,
					notification: write.notification,
					enabled: write.enabled,
					scope: write.scope || null,
				})
			} catch (error) {
				// Said out loud, and then re-read. A failed write that left
				// the switch where the click put it is a setting somebody
				// believes they made.
				this.message = error?.response?.status === 403
					? t('dossiq', 'An administrator decides this one, so it is not yours to change.')
					: t('dossiq', 'That setting was not saved.')
			}

			await this.load()
		},

		/**
		 * Set a team's default.
		 *
		 * The platform refuses with a 403 unless the writer administers the
		 * group, and that refusal is shown rather than second-guessed here.
		 *
		 * @param {boolean} enabled Whether the team gets it by default.
		 * @return {Promise<void>} When the write has finished.
		 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
		 */
		async setGroupDefault(enabled) {
			this.groupMessage = ''
			try {
				await saveGroupDefault({
					group: this.group.trim(),
					schema: this.selectedGroupEntry.schema,
					notification: this.selectedGroupEntry.notification,
					enabled,
					scope: this.domain ? `domain:${this.domain}` : null,
				})
				this.groupMessage = t('dossiq', 'The team default is set.')
			} catch (error) {
				this.groupMessage =
					error?.response?.status === 403
						? t(
								'dossiq',
								'You do not administer that group, so its default is not yours to set.',
							)
						: t('dossiq', 'The team default was not set.')
			}

			await this.load()
		},
	},
}
</script>

<style scoped>
.notification-routing {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.notification-routing__explainer {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
}

.notification-routing__group {
	border-top: 1px solid var(--color-border);
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-top: 16px;
}

.notification-routing__group-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.notification-routing__message {
	margin: 0;
}
</style>
