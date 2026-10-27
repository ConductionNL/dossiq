<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The app's own sections of the user settings dialog that CnAppRoot hosts
  (its board look draws them as the board's compact account dialog, each
  section a nav entry with its icon).

  Every per-person preference lives here. Dossiq has no page under Nextcloud's
  personal settings (Ruben, 11 Oct 2026: user configuration goes into the user
  settings dialog, app configuration into the admin settings).

  These sections go into the #user-settings-sections slot, beside the
  library's credentials, guided tour and integrations sections. The library's
  generic Notifications pane is left out (App.vue userSettingsSections):
  "Which notices reach me" is dossiq's screen over the same OpenRegister
  preferences, with the deciding layer named per row, so two notification
  sections would show one setting twice.

  @spec openspec/specs/personal-settings-surface/spec.md
-->
<template>
	<!-- Order, names and descriptions follow board DqPersoonlijkeInstellingen. -->
	<NcAppSettingsSection id="notification-routing" :name="t('dossiq', 'Notices')">
		<template #icon>
			<BellOutline :size="18" />
		</template>
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
		<template #icon>
			<EmailNewsletter :size="18" />
		</template>
		<p class="dossiq-user-settings__intro">
			{{
				t(
					'dossiq',
					'Every working day, a mail with your open cases and tasks.',
				)
			}}
		</p>
		<CnSettingsFieldsSection
			:name="t('dossiq', 'Daily overview')"
			url="/apps/dossiq/api/personal-queue/digest"
			saveMethod="POST"
			:bare="true"
			:autoSave="true"
			:saveAll="true"
			:reloadAfterSave="true"
			:fields="workDigestFields" />
	</NcAppSettingsSection>

	<NcAppSettingsSection
		id="substitution"
		:name="t('dossiq', 'Cover while I am away')">
		<template #icon>
			<AccountSwitchOutline :size="18" />
		</template>
		<p class="dossiq-user-settings__intro">
			{{ t('dossiq', 'Who picks up your cases while you are away.') }}
		</p>
		<SubstitutionSettings />
	</NcAppSettingsSection>

	<NcAppSettingsSection
		id="email-matching"
		:name="t('dossiq', 'Link mail to cases')">
		<template #icon>
			<EmailArrowRightOutline :size="18" />
		</template>
		<p class="dossiq-user-settings__intro">
			{{ t('dossiq', 'Mail about your cases reaches the case by itself.') }}
		</p>
		<CaseEmailMatchSettings />
	</NcAppSettingsSection>
</template>

<script>
import { CnSettingsFieldsSection } from '@conduction/nextcloud-vue'
import { NcAppSettingsSection } from '@nextcloud/vue'
import AccountSwitchOutline from 'vue-material-design-icons/AccountSwitchOutline.vue'
import BellOutline from 'vue-material-design-icons/BellOutline.vue'
import EmailArrowRightOutline from 'vue-material-design-icons/EmailArrowRightOutline.vue'
import EmailNewsletter from 'vue-material-design-icons/EmailNewsletter.vue'
import CaseEmailMatchSettings from './CaseEmailMatchSettings.vue'
import NotificationRoutingSettings from './NotificationRoutingSettings.vue'
import SubstitutionSettings from './SubstitutionSettings.vue'
import { workDigestFields } from './adminFields.js'

export default {
	name: 'UserSettings',

	components: {
		AccountSwitchOutline,
		BellOutline,
		EmailArrowRightOutline,
		EmailNewsletter,
		CaseEmailMatchSettings,
		CnSettingsFieldsSection,
		NcAppSettingsSection,
		NotificationRoutingSettings,
		SubstitutionSettings,
	},

	computed: {
		/**
		 * The daily digest: on or off, the hour, and who decided it.
		 *
		 * @return {Array<object>} The fields.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		workDigestFields() {
			return workDigestFields(this.t.bind(this))
		},
	},
}
</script>

<style scoped>
/* A section's description sits 16px above its content, as the board draws
   it (DqPersoonlijkeInstellingen "Meldingen"): the section content already
   sets its children 24px apart, so the description gives 8px back. */
.dossiq-user-settings__intro {
	margin-block: 0 -8px;
	color: var(--color-text-maxcontrast);
}
</style>
