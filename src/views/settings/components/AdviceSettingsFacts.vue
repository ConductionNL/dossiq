<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The VTH advice card: the standard term of an advice request, the active
  advisory bodies and when a reminder goes out. The term and the reminder are
  the consultation settings on System; the advisers are the advisory bodies
  in OpenRegister.

  @spec openspec/specs/vth-admin-settings/spec.md
-->
<template>
	<div class="advice-settings-facts" data-testid="advice-settings-facts">
		<NcLoadingIcon v-if="loading" :size="24" />
		<SettingsFactsList v-else :rows="rows" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'
import SettingsFactsList from './SettingsFactsList.vue'
import { adviceRows } from '../adminSettingsBlocks.js'

/** OpenRegister's list of dossiq's advisory bodies. */
const ADVISORY_BODIES_URL = '/apps/openregister/api/objects/dossiq/advisoryBody'

export default {
	name: 'AdviceSettingsFacts',

	components: { NcLoadingIcon, SettingsFactsList },

	data() {
		return {
			loading: true,
			bodies: [],
			settings: loadState('dossiq', 'consultationSettings', {}),
		}
	},

	computed: {
		/**
		 * The card's rows.
		 *
		 * @return {Array<object>} Label and value rows.
		 * @spec openspec/specs/vth-admin-settings/spec.md
		 */
		rows() {
			return adviceRows(this.t.bind(this), this.settings, this.bodies)
		},
	},

	/**
	 * Read the active advisory bodies.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/vth-admin-settings/spec.md
	 */
	async mounted() {
		try {
			const { data } = await axios.get(generateUrl(ADVISORY_BODIES_URL), { params: { active: true, _limit: 50 } })
			this.bodies = Array.isArray(data?.results) ? data.results : []
		} catch {
			this.bodies = []
		} finally {
			this.loading = false
		}
	},
}
</script>
