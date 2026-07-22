import { createApp } from 'vue'
import pinia from './pinia.js'
import AdminRoot from './views/settings/AdminRoot.vue'

const app = createApp(AdminRoot)
app.use(pinia)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.mount('#procest-settings')
