import Vue from 'vue'
import MyTasksWidget from './views/widgets/MyTasksWidget.vue'

OCA.Dashboard.register('procest_my_tasks_widget', (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })

	const View = Vue.extend(MyTasksWidget)
	new View({
		propsData: { title: widget.title },
	}).$mount(el)
})
