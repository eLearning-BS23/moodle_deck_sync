<template>
	<div class="moodle-deck-sync-settings">
		<h3>Moodle Deck Sync Administration</h3>

		<div class="form-group">
			<label for="allowed-instances">Allowed Moodle Instances (one per line)</label>
			<textarea
				id="allowed-instances"
				v-model="allowedInstancesText"
				rows="3"
				class="input-field" />
		</div>

		<div class="form-group">
			<label for="shared-secret">Shared Secret (HMAC-SHA256)</label>
			<input
				id="shared-secret"
				v-model="sharedSecret"
				type="password"
				placeholder="Leave empty to keep existing secret"
				class="input-field">
		</div>

		<div class="form-group">
			<label for="bot-username">Deck Bot Username</label>
			<input
				id="bot-username"
				v-model="botUsername"
				type="text"
				placeholder="e.g. moodle_deck_bot"
				class="input-field">
		</div>

		<div class="form-group">
			<label for="bot-password">Deck Bot App Password</label>
			<input
				id="bot-password"
				v-model="botAppPassword"
				type="password"
				placeholder="Leave empty to keep existing password"
				class="input-field">
		</div>

		<div class="form-group checkbox-group">
			<label>
				<input v-model="provisioningEnabled" type="checkbox">
				Enable Automatic User Provisioning
			</label>
		</div>

		<div class="form-group">
			<label for="purge-days">Purge Archived Boards After (Days, 0 = Disabled)</label>
			<input
				id="purge-days"
				v-model.number="purgeAfterDays"
				type="number"
				min="0"
				class="input-field">
		</div>

		<div class="form-actions">
			<button class="button primary" :disabled="saving" @click="saveSettings">
				{{ saving ? 'Saving...' : 'Save Settings' }}
			</button>
			<span v-if="statusMessage" :class="statusClass" class="status-msg">{{ statusMessage }}</span>
		</div>
	</div>
</template>

<script>
export default {
	name: 'AdminSettings',
	props: {
		settingsUrl: {
			type: String,
			required: true,
		},

		requestToken: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			allowedInstancesText: 'https://moodle.local',
			sharedSecret: '',
			botUsername: '',
			botAppPassword: '',
			provisioningEnabled: false,
			purgeAfterDays: 0,
			saving: false,
			statusMessage: '',
			statusClass: '',
		}
	},

	mounted() {
		this.fetchSettings()
	},

	methods: {
		async fetchSettings() {
			try {
				const headers = { Accept: 'application/json' }
				const token = this.requestToken || window.OC?.requestToken || ''
				if (token) {
					headers.requesttoken = token
					headers['OCS-APIREQUEST'] = 'true'
				}
				const response = await fetch(this.settingsUrl, { headers })
				if (response.ok) {
					const data = await response.json()
					if (Array.isArray(data.allowed_instances)) {
						this.allowedInstancesText = data.allowed_instances.join('\n')
					}
					this.botUsername = data.bot_username || ''
					this.provisioningEnabled = !!data.provisioning_enabled
					this.purgeAfterDays = data.purge_after_days || 0
				}
			} catch {
				this.statusMessage = 'Failed to load settings'
				this.statusClass = 'error'
			}
		},

		async saveSettings() {
			this.saving = true
			this.statusMessage = ''
			const allowed = this.allowedInstancesText
				.split('\n')
				.map((s) => s.trim())
				.filter((s) => s.length > 0)

			const payload = {
				allowed_instances: allowed,
				bot_username: this.botUsername,
				provisioning_enabled: this.provisioningEnabled,
				purge_after_days: this.purgeAfterDays,
			}

			if (this.sharedSecret) {
				payload.shared_secret = this.sharedSecret
			}
			if (this.botAppPassword) {
				payload.bot_app_password = this.botAppPassword
			}

			const token = this.requestToken || window.OC?.requestToken || ''
			const headers = {
				'Content-Type': 'application/json',
				'OCS-APIREQUEST': 'true',
			}
			if (token) {
				headers.requesttoken = token
			}

			try {
				const response = await fetch(this.settingsUrl, {
					method: 'PUT',
					headers,
					body: JSON.stringify(payload),
				})

				if (response.ok) {
					this.statusMessage = 'Settings saved successfully'
					this.statusClass = 'success'
					this.sharedSecret = ''
					this.botAppPassword = ''
				} else {
					this.statusMessage = 'Failed to save settings'
					this.statusClass = 'error'
				}
			} catch {
				this.statusMessage = 'Error saving settings'
				this.statusClass = 'error'
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.moodle-deck-sync-settings {
  max-width: 600px;
  padding: 16px 0;
}
.form-group {
  margin-bottom: 16px;
}
.form-group label {
  display: block;
  font-weight: 600;
  margin-bottom: 6px;
}
.input-field {
  width: 100%;
  padding: 8px;
  box-sizing: border-box;
}
.checkbox-group label {
  font-weight: normal;
}
.form-actions {
  margin-top: 20px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.status-msg {
  font-weight: 600;
}
.status-msg.success {
  color: #108043;
}
.status-msg.error {
  color: #c52828;
}
</style>
