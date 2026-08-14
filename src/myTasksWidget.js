import { createApp } from 'vue'
import MyTasksWidget from './views/widgets/MyTasksWidget.vue'
import pinia from './pinia.js'

OCA.Dashboard.register('procest_my_tasks_widget', async (el, { widget }) => {
	const app = createApp(MyTasksWidget, { title: widget.title })
	app.use(pinia)
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.mount(el)
})
