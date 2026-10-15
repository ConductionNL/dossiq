import { createApp } from 'vue'
import AdminRoot from './views/settings/AdminRoot.vue'
import pinia from './pinia.js'

// Library CSS, as in main.js: an explicit import, because webpack tree-shakes
// side-effect imports from aliased packages. Without it the admin page drew
// every library component unstyled (no table scroll box, no card look).
import '@conduction/nextcloud-vue/css/index.css'

const app = createApp(AdminRoot)
app.use(pinia)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.mount('#dossiq-settings')
