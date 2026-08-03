import { createApp } from 'vue'
import AdminSettings from './AdminSettings.vue'

document.addEventListener('DOMContentLoaded', () => {
	const el = document.querySelector('.moodle-deck-sync-settings-app')
	if (el) {
		createApp(AdminSettings, {
			settingsUrl: el.dataset.settingsUrl || '',
			requestToken: window.OC?.requestToken || '',
		}).mount(el)
	}
})
