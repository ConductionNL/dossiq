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
 */
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { createApp, h } from 'vue'
import CaseEmailMatchSettings from './views/settings/CaseEmailMatchSettings.vue'
import SubstitutionSettings from './views/settings/SubstitutionSettings.vue'
import pinia from './pinia.js'

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
