<?php

use OCP\Util;

$appId = OCA\Procest\AppInfo\Application::APP_ID;
// Shared splitChunks bundles (Vue / @nextcloud/vue / @conduction/nextcloud-vue);
// the -settings entry depends on them, so they must load first.
Util::addScript($appId, $appId . '-shared-vendor');
Util::addScript($appId, $appId . '-shared-nc-vue');
Util::addScript($appId, $appId . '-settings');
?>
<div id="procest-settings" data-version="<?php p($_['version'] ?? ''); ?>"></div>
