<template>
  <SettingsSection title="Doorlooptijd Configuration">
    <div class="doorlooptijd-settings">
      <div class="setting-group">
        <label for="streeftermijn">Target Time (Streeftermijn) - days</label>
        <input
          id="streeftermijn"
          v-model="settings.streeftermijn"
          type="number"
          min="1"
          class="setting-input"
          @change="saveSetting('streeftermijn')"
        >
      </div>

      <div class="setting-group">
        <label for="fatalTermijn">Deadline (Fatale Termijn) - days</label>
        <input
          id="fatalTermijn"
          v-model="settings.fatalTermijn"
          type="number"
          min="1"
          class="setting-input"
          @change="saveSetting('fatalTermijn')"
        >
      </div>

      <div class="setting-group">
        <label for="suspensionStatuses">Suspension Status Identifiers</label>
        <input
          id="suspensionStatuses"
          v-model="settings.suspensionStatuses"
          type="text"
          placeholder="suspended,on_hold"
          class="setting-input"
          @change="saveSetting('suspensionStatuses')"
        >
        <p class="description">
          Comma-separated list of case statuses that should be excluded from doorlooptijd calculation
        </p>
      </div>

      <div v-if="saved" class="success-message">
        Settings saved successfully
      </div>
    </div>
  </SettingsSection>
</template>

<script>
/**
 * Doorlooptijd Admin Settings Component
 *
 * Admin settings for configuring doorlooptijd tracking defaults.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-17
 */
import { showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
  name: 'DoorlooptijdAdmin',
  data() {
    return {
      settings: {
        streeftermijn: 30,
        fatalTermijn: 60,
        suspensionStatuses: 'suspended,on_hold',
      },
      saved: false,
    }
  },
  methods: {
    async saveSetting(key) {
      try {
        await axios.post(
          generateUrl('/apps/procest/api/settings'),
          {
            [`doorlooptijd_${key}`]: this.settings[key],
          }
        )
        this.saved = true
        setTimeout(() => {
          this.saved = false
        }, 3000)
        showSuccess('Setting saved')
      } catch (error) {
        console.error('Error saving setting:', error)
      }
    },
  },
}
</script>

<style scoped>
.doorlooptijd-settings {
  padding: 20px;
}

.setting-group {
  margin-bottom: 20px;
  display: flex;
  flex-direction: column;
}

.setting-group label {
  font-weight: 600;
  margin-bottom: 8px;
  font-size: 14px;
}

.setting-input {
  padding: 8px 12px;
  border: 1px solid #ccc;
  border-radius: 4px;
  font-size: 14px;
  width: 300px;
}

.setting-input:focus {
  outline: none;
  border-color: #0082c9;
  box-shadow: 0 0 0 2px rgba(0, 130, 201, 0.1);
}

.description {
  margin: 5px 0 0 0;
  font-size: 12px;
  color: #999;
}

.success-message {
  padding: 10px 15px;
  background: #c3e6cb;
  border: 1px solid #b1dfbb;
  border-radius: 4px;
  color: #155724;
  margin-top: 15px;
}
</style>
