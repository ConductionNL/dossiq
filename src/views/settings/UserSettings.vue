<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The sections of dossiq's user settings dialog (the NcAppSettingsDialog that
  CnAppRoot hosts and the settings foldout opens).

  Every per-person preference lives here. Dossiq has no page under Nextcloud's
  personal settings any more (Ruben, 11 Oct 2026: user configuration goes into
  the user settings dialog, app configuration into the admin settings). The
  five sections below are the ones /settings/user/dossiq carried until then.

  THE LIBRARY'S OWN SECTIONS ARE REPEATED HERE ON PURPOSE. Filling CnAppRoot's
  #user-settings slot replaces its fallback content entirely, and nothing
  reports that: the credentials section and the walkthrough replay would have
  vanished from the dialog in silence. So this renders credentials itself, and
  App.vue hands in the walkthrough state CnAppRoot owns.

  The library's generic Notifications pane is not repeated. "Which notices
  reach me" is dossiq's screen over the same OpenRegister preferences, with
  the deciding layer named per row, so two notification sections would show
  one setting twice.

  @spec openspec/specs/personal-settings-surface/spec.md
-->
<template>
	<!-- Order, names and descriptions follow board DqPersoonlijkeInstellingen. -->
	<NcAppSettingsSection
		id="menu-case-types"
		:name="t('dossiq', 'Case types in my menu')">
		<p class="dossiq-user-settings__intro">
			{{
				t(
					'dossiq',
					'These case types are in your menu under My case types, in this order. Drag a row or use the arrow keys.',
				)
			}}
		</p>
		<MenuCaseTypesSettings />
	</NcAppSettingsSection>

	<NcAppSettingsSection id="notification-routing" :name="t('dossiq', 'Notices')">
		<p class="dossiq-user-settings__intro">
			{{
				t(
					'dossiq',
					'Choose per kind of notice where you get it. Your organisation decides which ones are required.',
				)
			}}
		</p>
		<NotificationRoutingSettings />
	</NcAppSettingsSection>

	<NcAppSettingsSection id="work-digest" :name="t('dossiq', 'Daily overview')">
		<p class="dossiq-user-settings__intro">
			{{
				t(
					'dossiq',
					'Every working day, a mail with your open cases and tasks.',
				)
			}}
		</p>
		<WorkDigestSettings />
	</NcAppSettingsSection>

	<NcAppSettingsSection
		id="substitution"
		:name="t('dossiq', 'Cover while I am away')">
		<p class="dossiq-user-settings__intro">
			{{ t('dossiq', 'Who picks up your cases while you are away.') }}
		</p>
		<SubstitutionSettings />
	</NcAppSettingsSection>

	<NcAppSettingsSection
		id="email-matching"
		:name="t('dossiq', 'Link mail to cases')">
		<p class="dossiq-user-settings__intro">
			{{ t('dossiq', 'Mail about your cases reaches the case by itself.') }}
		</p>
		<CaseEmailMatchSettings />
	</NcAppSettingsSection>

	<NcAppSettingsSection id="credentials" :name="t('dossiq', 'Credentials')">
		<p class="dossiq-user-settings__intro">
			{{
				t(
					'dossiq',
					'Your own accounts with other services. They are stored encrypted.',
				)
			}}
		</p>
		<CnCredentials
			scope="personal"
			appId="dossiq"
			:appName="(manifest && manifest.name) || 'Dossiq'"
			:appCredentials="(manifest && manifest.credentials) || []" />
	</NcAppSettingsSection>

	<NcAppSettingsSection
		v-if="walkthrough"
		id="cn-walkthrough"
		:name="t('dossiq', 'Guided tour')">
		<p class="dossiq-user-settings__intro">
			{{ walkthrough.hint }}
		</p>
		<div class="dossiq-user-settings__actions">
			<NcButton
				v-if="walkthrough.inProgress"
				variant="primary"
				data-testid="cn-walkthrough-continue"
				@click="$emit('continueWalkthrough')">
				{{ t('dossiq', 'Continue the tour') }}
			</NcButton>
			<NcButton
				variant="secondary"
				data-testid="cn-walkthrough-restart"
				@click="$emit('restartWalkthrough')">
				{{ t('dossiq', 'Start again') }}
			</NcButton>
		</div>
	</NcAppSettingsSection>
</template>

<script>
import { CnCredentials } from '@conduction/nextcloud-vue'
import { NcAppSettingsSection, NcButton } from '@nextcloud/vue'
import CaseEmailMatchSettings from './CaseEmailMatchSettings.vue'
import MenuCaseTypesSettings from './MenuCaseTypesSettings.vue'
import NotificationRoutingSettings from './NotificationRoutingSettings.vue'
import SubstitutionSettings from './SubstitutionSettings.vue'
import WorkDigestSettings from './WorkDigestSettings.vue'

export default {
	name: 'UserSettings',

	components: {
		CaseEmailMatchSettings,
		CnCredentials,
		MenuCaseTypesSettings,
		NcAppSettingsSection,
		NcButton,
		NotificationRoutingSettings,
		SubstitutionSettings,
		WorkDigestSettings,
	},

	props: {
		/**
		 * The app manifest, for the credentials it declares.
		 */
		manifest: {
			type: Object,
			default: null,
		},

		/**
		 * The walkthrough replay CnAppRoot would have drawn, or null when the
		 * app declares no tour. Labels come from CnAppRoot so the wording stays
		 * the library's.
		 *
		 * @type {{sectionName: string, hint: string, inProgress: boolean, continueLabel: string, restartLabel: string}|null}
		 */
		walkthrough: {
			type: Object,
			default: null,
		},
	},

	emits: ['continueWalkthrough', 'restartWalkthrough'],
}
</script>

<style scoped>
.dossiq-user-settings__intro {
	margin-bottom: calc(var(--default-grid-baseline) * 3);
	color: var(--color-text-maxcontrast);
}

.dossiq-user-settings__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
