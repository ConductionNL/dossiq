import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import Dashboard from '../views/Dashboard.vue'
import MyWork from '../views/MyWork.vue'
import CaseList from '../views/cases/CaseList.vue'
import CaseDetail from '../views/cases/CaseDetail.vue'
import TaskList from '../views/tasks/TaskList.vue'
import TaskDetail from '../views/tasks/TaskDetail.vue'
import AdminRoot from '../views/settings/AdminRoot.vue'
import ComplaintList from '../views/complaints/ComplaintList.vue'
import ComplaintDetail from '../views/complaints/ComplaintDetail.vue'

Vue.use(Router)

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/procest'),
	routes: [
		{ path: '/', name: 'Dashboard', component: Dashboard },
		{ path: '/my-work', name: 'MyWork', component: MyWork },
		{ path: '/cases', name: 'Cases', component: CaseList },
		{ path: '/cases/:id', name: 'CaseDetail', component: CaseDetail, props: route => ({ caseId: route.params.id }) },
		{ path: '/tasks', name: 'Tasks', component: TaskList },
		{ path: '/tasks/new', name: 'TaskNew', component: TaskDetail, props: route => ({ taskId: 'new', caseIdProp: route.query.caseId || null }) },
		{ path: '/tasks/:id', name: 'TaskDetail', component: TaskDetail, props: route => ({ taskId: route.params.id }) },
		{ path: '/complaints', name: 'Complaints', component: ComplaintList },
		{ path: '/complaints/new', name: 'ComplaintNew', component: ComplaintDetail, props: () => ({ complaintId: 'new' }) },
		{ path: '/complaints/:id', name: 'ComplaintDetail', component: ComplaintDetail, props: route => ({ complaintId: route.params.id }) },
		{ path: '/settings', name: 'Settings', component: AdminRoot },
		{ path: '/case-types', name: 'CaseTypes', component: AdminRoot },
		{ path: '*', redirect: '/' },
	],
})
