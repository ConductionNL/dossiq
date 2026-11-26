<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Plan a session the case's case type declares.

  Reads the case's declared sessions (`GET /api/cases/{caseId}/sessions`,
  from `caseType.sessions[]`): a complaint's hearing, a meeting with the
  parties, whatever the case type calls it. The handler picks the session
  when there is more than one, sets when and where, and names who is
  invited, one field per role the session invites. The dialog posts the plan
  (`POST /api/cases/{caseId}/sessions`); the server keeps the session's
  inspection floor and invitee roles, and a refusal stays in the dialog in
  the server's words. The handler chairs the session.

  @spec openspec/changes/complaints-are-a-case-type/tasks.md#2.3
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Plan a session')"
		data-testid="session-schedule"
		@closing="$emit('close')">
		<div class="session-schedule">
			<p v-if="loading" data-testid="session-schedule-loading">
				{{ t('dossiq', 'Loading the sessions of this case …') }}
			</p>
			<p v-else-if="definitions.length === 0 && error === ''" data-testid="session-schedule-empty">
				{{ t('dossiq', 'The case type of this case declares no session.') }}
			</p>
			<template v-else-if="definitions.length > 0">
				<NcSelect
					v-if="definitions.length > 1"
					v-model="sessionKey"
					data-testid="session-schedule-session"
					:inputLabel="t('dossiq', 'Session')"
					:options="sessionOptions"
					:reduce="(option) => option.id"
					label="label" />
				<NcDateTimePicker
					v-model="when"
					data-testid="session-schedule-when"
					type="datetime"
					:label="t('dossiq', 'When')" />
				<NcTextField
					:modelValue="location"
					data-testid="session-schedule-location"
					:label="t('dossiq', 'Where')"
					@update:modelValue="(value) => location = value" />
				<NcTextField
					v-for="role in roles"
					:key="role"
					:modelValue="names[role] || ''"
					:data-testid="`session-schedule-invitee-${role}`"
					:label="role"
					@update:modelValue="(value) => setName(role, value)" />
				<p v-if="selected && selected.inspectionFloorDays > 0" class="session-schedule__note" data-testid="session-schedule-floor">
					{{ n('dossiq', 'The parties get at least %n day to inspect the documents before the session.', 'The parties get at least %n days to inspect the documents before the session.', selected.inspectionFloorDays) }}
				</p>
			</template>
			<p
				v-if="error"
				class="session-schedule__error"
				role="alert"
				data-testid="session-schedule-error">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton data-testid="session-schedule-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="session-schedule-submit"
				:disabled="!canPlan || saving"
				variant="primary"
				@click="plan">
				<template #icon>
					<CalendarClock :size="20" />
				</template>
				{{ saving ? t('dossiq', 'Saving …') : t('dossiq', 'Plan') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePicker from '@nextcloud/vue/components/NcDateTimePicker'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'

/**
 * The URL of a case's sessions.
 *
 * @param {string} caseId The case uuid.
 * @return {string}
 */
function sessionsUrl(caseId) {
	return generateUrl(`/apps/dossiq/api/cases/${encodeURIComponent(caseId)}/sessions`)
}

/**
 * The message a refused call carries, else the fallback.
 *
 * @param {Error} e The axios error.
 * @param {string} fallback What to say when the server said nothing.
 * @return {string}
 */
function refusalOf(e, fallback) {
	return e?.response?.data?.message || e?.response?.data?.error || fallback
}

export default {
	name: 'SessionScheduleDialog',

	components: { CalendarClock, NcButton, NcDateTimePicker, NcDialog, NcSelect, NcTextField },

	props: {
		/** The case uuid whose session is planned. */
		caseId: { type: String, required: true },
	},

	emits: ['close', 'done'],

	data() {
		return {
			definitions: [],
			sessionKey: null,
			when: null,
			location: '',
			names: {},
			loading: false,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The declared sessions in the picker.
		 *
		 * @return {Array<{id: string, label: string}>}
		 *
		 * @spec openspec/changes/complaints-are-a-case-type/tasks.md#2.3
		 */
		sessionOptions() {
			return this.definitions.map((def) => ({ id: def.key, label: def.label || def.key }))
		},

		/**
		 * The chosen session's definition.
		 *
		 * @return {object|null}
		 *
		 * @spec openspec/changes/complaints-are-a-case-type/tasks.md#2.3
		 */
		selected() {
			return this.definitions.find((def) => def.key === this.sessionKey) ?? null
		},

		/**
		 * The roles the chosen session invites, one name field each.
		 *
		 * @return {Array<string>}
		 *
		 * @spec openspec/changes/complaints-are-a-case-type/tasks.md#2.3
		 */
		roles() {
			return Array.isArray(this.selected?.inviteeRoles) ? this.selected.inviteeRoles : []
		},

		/**
		 * The invitees as the server reads them: one per role with a name.
		 *
		 * @return {Array<{role: string, name: string}>}
		 *
		 * @spec openspec/changes/complaints-are-a-case-type/tasks.md#2.3
		 */
		invitees() {
			return this.roles
				.map((role) => ({ role, name: String(this.names[role] ?? '').trim() }))
				.filter((invitee) => invitee.name !== '')
		},

		/**
		 * Whether a session and a moment are chosen and enough people are invited.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/complaints-are-a-case-type/tasks.md#2.3
		 */
		canPlan() {
			return this.selected !== null
				&& this.when instanceof Date
				&& Number.isNaN(this.when.getTime()) === false
				&& this.invitees.length >= Number(this.selected.minInvitees ?? 0)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		/**
		 * Read the case's declared sessions; the only one is chosen.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/complaints-are-a-case-type/tasks.md#2.3
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(sessionsUrl(this.caseId))
				this.definitions = Array.isArray(data?.definitions) ? data.definitions : []
				if (this.definitions.length === 1) {
					this.sessionKey = this.definitions[0].key
				}
			} catch (e) {
				this.definitions = []
				this.error = refusalOf(e, t('dossiq', 'The sessions of this case could not be read.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Keep the name typed for one role.
		 *
		 * @param {string} role The invitee role.
		 * @param {string} value What the handler typed.
		 * @return {void}
		 *
		 * @spec openspec/changes/complaints-are-a-case-type/tasks.md#2.3
		 */
		setName(role, value) {
			this.names = { ...this.names, [role]: value }
		},

		/**
		 * Post the plan; close on success, keep a refusal in the dialog.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/complaints-are-a-case-type/tasks.md#2.3
		 */
		async plan() {
			if (this.canPlan === false || this.saving) {
				return
			}
			this.saving = true
			this.error = ''
			const location = this.location.trim()
			try {
				const { data } = await axios.post(sessionsUrl(this.caseId), {
					sessionKey: this.sessionKey,
					scheduledDate: this.when.toISOString(),
					invitees: this.invitees,
					payload: location === '' ? {} : { location },
				})
				showSuccess(t('dossiq', '{session} planned.', { session: this.selected.label || this.selected.key }))
				this.$emit('done', data)
				this.$emit('close')
			} catch (e) {
				this.error = refusalOf(e, t('dossiq', 'The session could not be planned.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.session-schedule {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.session-schedule__note {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.session-schedule__error {
	color: var(--color-error-text);
}
</style>
