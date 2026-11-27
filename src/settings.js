import { registerTranslations } from '@conduction/nextcloud-vue'
import { createApp } from 'vue'
import AdminRoot from './views/settings/AdminRoot.vue'
import pinia from './pinia.js'

// Sets __webpack_public_path__ before any dynamic import() runs, or the
// library's lazy chunks (the setup wizard's status fetch) resolve against
// /settings/admin and the wizard's cards come up empty.
import './publicPath.js'
// Library CSS: templates/settings/admin.php links css/nextcloud-vue.css (copied
// there by the build). Without it the admin page drew every library component
// unstyled (no table scroll box, no card look).

registerTranslations()

const app = createApp(AdminRoot)
app.use(pinia)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.mount('#dossiq-settings')
