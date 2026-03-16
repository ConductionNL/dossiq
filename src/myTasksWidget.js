import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import MyTasksWidget from './views/widgets/MyTasksWidget.vue'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('procest_my_tasks_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(MyTasksWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
