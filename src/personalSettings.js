/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Mount point for procest's personal settings (Settings -> Personal).
 *
 * Self-service substitution lives here, and so does linking your own mail to
 * your cases. The coordinator console stays an app page: it acts on other
 * people's records, which is not a personal setting.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-005
 */
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { createApp, h } from 'vue'
import CaseEmailMatchSettings from './views/settings/CaseEmailMatchSettings.vue'
import MenuCaseTypesSettings from './views/settings/MenuCaseTypesSettings.vue'
import NotificationRoutingSettings from './views/settings/NotificationRoutingSettings.vue'
import SubstitutionSettings from './views/settings/SubstitutionSettings.vue'
import WorkDigestSettings from './views/settings/WorkDigestSettings.vue'
import pinia from './pinia.js'

// Library CSS: this bundle mounts library components outside the app page, so
// it needs the stylesheet main.js imports. Without it every component here
// rendered unstyled (r4-tour-menu-labels-and-settings-styles).
import '@conduction/nextcloud-vue/css/index.css'

const app = createApp(SubstitutionSettings)
app.use(pinia)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.mount('#dossiq-personal-settings')

// Linking your own mail to your cases (email-case-matching).
// Vue 3: props pass FLAT in h(); component children (arrays) become the default slot.
const emailMatching = createApp({
	render: () =>
		h(
			CnSettingsSection,
			{
				name: t('dossiq', 'Link my mail to cases'),
				description: t(
					'dossiq',
					'Mail that names a case number, like 2026-0042, is added to that case.',
				),
			},
			[h(CaseEmailMatchSettings)],
		),
})
emailMatching.config.globalProperties.t = t
emailMatching.config.globalProperties.n = n
emailMatching.mount('#dossiq-personal-email-matching')

// When the daily digest of your open work arrives (one-personal-queue).
// It is a personal setting because the choice is the reader's; the routed
// version is OpenRegister's notification routing, which this base does not
// carry yet.
const workDigest = createApp({
	render: () =>
		h(
			CnSettingsSection,
			{
				name: t('dossiq', 'My daily work digest'),
				description: t(
					'dossiq',
					'One message a day naming what is waiting on you. Nothing waiting means no message.',
				),
			},
			[h(WorkDigestSettings)],
		),
})
workDigest.config.globalProperties.t = t
workDigest.config.globalProperties.n = n
workDigest.mount('#dossiq-personal-work-digest')

// Which notices reach you, and which layer decided that (notifications routed
// by role and domain). It reads OpenRegister's preferences directly: the
// deciding layer is the platform's answer, and a dossiq copy of it could only
// disagree.
const notificationRouting = createApp({
	render: () =>
		h(
			CnSettingsSection,
			{
				name: t('dossiq', 'Which notices reach me'),
				description: t(
					'dossiq',
					'Every setting says who decided it. A setting your team made is one you can still change for yourself.',
				),
			},
			[h(NotificationRoutingSettings)],
		),
})
notificationRouting.config.globalProperties.t = t
notificationRouting.config.globalProperties.n = n
notificationRouting.mount('#dossiq-personal-notification-routing')

// The case types under My case types in the sidebar, in this user's order
// (case-types-in-my-menu, board DqPersoonlijkeInstellingen). The sidebar's
// pencil beside that heading opens this section.
const menuCaseTypes = createApp({
	render: () =>
		h(
			CnSettingsSection,
			{
				name: t('dossiq', 'Case types in my menu'),
				description: t(
					'dossiq',
					'These case types are in your dossiq menu, under My case types. One click opens their case list. Drag a handle to change the order.',
				),
			},
			[h(MenuCaseTypesSettings)],
		),
})
menuCaseTypes.config.globalProperties.t = t
menuCaseTypes.config.globalProperties.n = n
menuCaseTypes.mount('#dossiq-personal-menu-case-types')
