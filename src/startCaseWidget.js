import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import StartCaseWidget from './views/widgets/StartCaseWidget.vue'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('procest_start_case_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(StartCaseWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
