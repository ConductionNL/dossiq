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
	<NcAppSettingsSection
		id="menu-case-types"
		:name="t('dossiq', 'Case types in my menu')">
		<template #icon>
			<FormatListBulletedType :size="18" />
		</template>
		<p class="dossiq-user-settings__intro">
			{{
				t(
					'dossiq',
					'These case types are in your dossiq menu, under My case types. One click opens their case list. Drag a handle to change the order.',
				)
			}}
		</p>
		<MenuCaseTypesSettings />
	</NcAppSettingsSection>

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
		<WorkDigestSettings />
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
import { NcAppSettingsSection } from '@nextcloud/vue'
import AccountSwitchOutline from 'vue-material-design-icons/AccountSwitchOutline.vue'
import BellOutline from 'vue-material-design-icons/BellOutline.vue'
import EmailArrowRightOutline from 'vue-material-design-icons/EmailArrowRightOutline.vue'
import EmailNewsletter from 'vue-material-design-icons/EmailNewsletter.vue'
import FormatListBulletedType from 'vue-material-design-icons/FormatListBulletedType.vue'
import CaseEmailMatchSettings from './CaseEmailMatchSettings.vue'
import MenuCaseTypesSettings from './MenuCaseTypesSettings.vue'
import NotificationRoutingSettings from './NotificationRoutingSettings.vue'
import SubstitutionSettings from './SubstitutionSettings.vue'
import WorkDigestSettings from './WorkDigestSettings.vue'

export default {
	name: 'UserSettings',

	components: {
		AccountSwitchOutline,
		BellOutline,
		EmailArrowRightOutline,
		EmailNewsletter,
		FormatListBulletedType,
		CaseEmailMatchSettings,
		MenuCaseTypesSettings,
		NcAppSettingsSection,
		NotificationRoutingSettings,
		SubstitutionSettings,
		WorkDigestSettings,
	},
}
</script>

<style scoped>
.dossiq-user-settings__intro {
	margin-bottom: calc(var(--default-grid-baseline) * 3);
	color: var(--color-text-maxcontrast);
}
</style>
