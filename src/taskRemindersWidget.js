import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import TaskRemindersWidget from './views/widgets/TaskRemindersWidget.vue'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('procest_task_reminders_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(TaskRemindersWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
