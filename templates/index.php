<?php

use OCP\Util;

$appId = OCA\Dossiq\AppInfo\Application::APP_ID;
// Shared splitChunks bundles (Vue / @nextcloud/vue / @conduction/nextcloud-vue);
// the -main entry depends on them, so they must load first.
Util::addScript($appId, $appId . '-shared-vendor');
Util::addScript($appId, $appId . '-shared-nc-vue');
Util::addScript($appId, $appId . '-main');
?>
<div id="content"></div>
