import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import OverdueCasesWidget from './views/widgets/OverdueCasesWidget.vue'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('procest_overdue_cases_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(OverdueCasesWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
