import { createApp } from 'vue'
import pinia from './pinia.js'
import TaskRemindersWidget from './views/widgets/TaskRemindersWidget.vue'

OCA.Dashboard.register('procest_task_reminders_widget', async (el, { widget }) => {
	const app = createApp(TaskRemindersWidget, { title: widget.title })
	app.use(pinia)
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.mount(el)
})
