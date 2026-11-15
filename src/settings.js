import { registerTranslations } from '@conduction/nextcloud-vue'
import { createApp } from 'vue'
import AdminRoot from './views/settings/AdminRoot.vue'
import pinia from './pinia.js'

// Sets __webpack_public_path__ before any dynamic import() runs, or the
// library's lazy chunks (the setup wizard's status fetch) resolve against
// /settings/admin and the wizard's cards come up empty.
import './publicPath.js'
// Library CSS, as in main.js: an explicit import, because webpack tree-shakes
// side-effect imports from aliased packages. Without it the admin page drew
// every library component unstyled (no table scroll box, no card look).
import '@conduction/nextcloud-vue/css/index.css'

registerTranslations()

const app = createApp(AdminRoot)
app.use(pinia)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.mount('#dossiq-settings')
