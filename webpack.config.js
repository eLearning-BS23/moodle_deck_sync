import webpackConfig from '@nextcloud/webpack-vue-config'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const dirname = path.dirname(fileURLToPath(import.meta.url))

webpackConfig.entry = {
	'moodle-deck-sync-admin': path.join(dirname, 'src', 'main.js'),
}

export default webpackConfig
