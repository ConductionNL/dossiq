<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Which notices reach you, and which layer decided that.

  THE DECIDING LAYER IS THE FEATURE. A switch whose effect depends on a layer
  the screen does not show is a switch people stop trusting: they turn it off,
  keep getting the notice, and conclude the setting is broken. So every value
  says who set it, and a value a team default decided says so in words rather
  than just reading as off.

  A person can still overrule their team. Clearing their own value hands the
  decision back to the layer below, which is why "use the team's setting" is a
  button and not just the act of switching it back on.

  @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
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
			:description="t('dossiq', 'This instance routes no dossiq notifications yet.')" />

		<ul v-else class="notification-routing__list">
			<li v-for="entry in entries" :key="entryKey(entry)" class="notification-routing__item">
				<NcCheckboxRadioSwitch
					:modelValue="entry.enabled"
					type="switch"
					:data-testid="`notification-routing-switch-${entryKey(entry)}`"
					@update:modelValue="(v) => setOwn(entry, v)">
					{{ labelFor(entry) }}
				</NcCheckboxRadioSwitch>

				<p class="notification-routing__decided" :data-testid="`notification-routing-layer-${entryKey(entry)}`">
					{{ decidedBy(entry) }}
				</p>

				<NcButton
					v-if="entry.source === 'user-override'"
					variant="tertiary"
					:data-testid="`notification-routing-clear-${entryKey(entry)}`"
					@click="useLayerBelow(entry)">
					{{ t('dossiq', 'Use the setting from my team') }}
				</NcButton>
			</li>
		</ul>

		<div v-if="isAdmin" class="notification-routing__group">
			<h3>{{ t('dossiq', 'Set a default for a team') }}</h3>
			<p class="notification-routing__explainer">
				{{ t('dossiq', 'A team default decides for everyone in the group who has not chosen for themselves.') }}
			</p>

			<NcTextField
				:modelValue="group"
				:label="t('dossiq', 'Group')"
				data-testid="notification-routing-group"
				@update:modelValue="(v) => (group = v)" />

			<NcSelect
				:modelValue="selectedGroupEntry"
				:options="entries"
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

			<p v-if="groupMessage" class="notification-routing__message" data-testid="notification-routing-group-message">
				{{ groupMessage }}
			</p>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
	NcLoadingIcon,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import {
	clearPreference,
	fetchPreferences,
	saveGroupDefault,
	savePreference,
} from '../../services/notificationRoutingApi.js'

export default {
	name: 'NotificationRoutingSettings',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
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
			loading: true,
			domain: '',
			group: '',
			selectedGroupEntry: null,
			groupMessage: '',
		}
	},

	computed: {
		/**
		 * The domains a preference may be pinned to, plus the unpinned answer.
		 *
		 * @return {Array<{id: string, label: string}>} The options.
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
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
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
		 */
		selectedDomain() {
			return this.domainOptions.find((o) => o.id === this.domain) || this.domainOptions[0]
		},

		/**
		 * Whether a team default can be written from what is filled in.
		 *
		 * @return {boolean} TRUE when a group and a notification are chosen.
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
		 */
		canWriteGroupDefault() {
			return (this.group.trim() !== '' && this.selectedGroupEntry !== null)
		},
	},

	/**
	 * Read the reader's own effective preferences.
	 *
	 * @return {Promise<void>} When the read has finished.
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * Read the preferences as they apply in the chosen domain.
		 *
		 * @return {Promise<void>} When the read has finished.
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
		 */
		async load() {
			this.loading = true
			try {
				const scope = (this.domain ? `domain:${this.domain}` : null)
				const entries = await fetchPreferences(scope)
				this.entries = entries.map((entry) => ({
					...entry,
					notificationLabel: this.labelFor(entry),
				}))
			} finally {
				this.loading = false
			}
		},

		/**
		 * A stable key for one entry.
		 *
		 * @param {object} entry The entry.
		 * @return {string} The key.
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
		 */
		entryKey(entry) {
			return `${entry.schema}-${entry.notification}`
		},

		/**
		 * What to call this notification on screen.
		 *
		 * @param {object} entry The entry.
		 * @return {string} The label.
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
		 */
		labelFor(entry) {
			const known = {
				caseAssigned: t('dossiq', 'A case is assigned to me'),
				caseHandoffIntake: t('dossiq', 'A case reaches us through a handoff'),
				substitutionRegisteredForSubstitute: t('dossiq', 'I am registered to stand in for someone'),
				workDigestReady: t('dossiq', 'My daily work digest'),
			}

			return (known[entry.notification] || `${entry.schemaTitle || entry.schema}: ${entry.notification}`)
		},

		/**
		 * Which layer decided this value, in words.
		 *
		 * @param {object} entry The entry.
		 * @return {string} The sentence.
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
		 */
		decidedBy(entry) {
			const scoped = (entry.scope && entry.scope !== 'global')
			if (entry.source === 'user-override') {
				return (scoped
					? t('dossiq', 'You set this, for this part of your work only.')
					: t('dossiq', 'You set this.'))
			}

			if (entry.source === 'group-default') {
				return (scoped
					? t('dossiq', 'Your team set this, for this part of your work only. You can decide for yourself.')
					: t('dossiq', 'Your team set this. You can decide for yourself.'))
			}

			return t('dossiq', 'Nobody has changed this, so it is on the setting it ships with.')
		},

		/**
		 * Show the preferences as they apply in one domain.
		 *
		 * @param {?{id: string}} option The chosen domain.
		 * @return {Promise<void>} When the read has finished.
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
		 */
		async chooseDomain(option) {
			this.domain = (option ? option.id : '')
			await this.load()
		},

		/**
		 * Record the reader's own value.
		 *
		 * @param {object} entry The entry.
		 * @param {boolean} enabled Whether they want it.
		 * @return {Promise<void>} When the write has finished.
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
		 */
		async setOwn(entry, enabled) {
			await savePreference({
				schema: entry.schema,
				notification: entry.notification,
				enabled,
				scope: (this.domain ? `domain:${this.domain}` : null),
			})
			await this.load()
		},

		/**
		 * Hand the decision back to the layer below.
		 *
		 * @param {object} entry The entry.
		 * @return {Promise<void>} When the write has finished.
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
		 */
		async useLayerBelow(entry) {
			await clearPreference({
				schema: entry.schema,
				notification: entry.notification,
				scope: (this.domain ? `domain:${this.domain}` : null),
			})
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
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
		 */
		async setGroupDefault(enabled) {
			this.groupMessage = ''
			try {
				await saveGroupDefault({
					group: this.group.trim(),
					schema: this.selectedGroupEntry.schema,
					notification: this.selectedGroupEntry.notification,
					enabled,
					scope: (this.domain ? `domain:${this.domain}` : null),
				})
				this.groupMessage = t('dossiq', 'The team default is set.')
			} catch (error) {
				this.groupMessage = (error?.response?.status === 403
					? t('dossiq', 'You do not administer that group, so its default is not yours to set.')
					: t('dossiq', 'The team default was not set.'))
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

.notification-routing__list {
	display: flex;
	flex-direction: column;
	gap: 12px;
	list-style: none;
	padding: 0;
}

.notification-routing__item {
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 12px;
}

.notification-routing__decided,
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
