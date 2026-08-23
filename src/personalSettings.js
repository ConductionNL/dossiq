/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Mount point for procest's personal settings (Settings -> Personal).
 *
 * Only self-service substitution lives here. The coordinator console stays an
 * app page: it acts on other people's records, which is not a personal setting.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
 */
import { createApp } from 'vue'
import SubstitutionSettings from './views/settings/SubstitutionSettings.vue'
import pinia from './pinia.js'

const app = createApp(SubstitutionSettings)
app.use(pinia)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.mount('#procest-personal-settings')
