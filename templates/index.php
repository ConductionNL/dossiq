<?php

use OCP\Util;

$appId = OCA\Procest\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-shared-vendor');
Util::addScript($appId, $appId . '-shared-nc-vue');
Util::addScript($appId, $appId . '-main');
?>
<script nonce="<?php p(\OC::$server->getContentSecurityPolicyNonceManager()->getNonce()); ?>">
// Browser polyfill for `process` — some bundled deps reference globalThis.process
// at module-init time. Webpack's NodePolyfillPlugin would normally inject this,
// but the current build does not, so seed it here before deferred scripts run.
window.process = window.process || { env: { NODE_ENV: 'production' }, browser: true, platform: 'browser', version: '', versions: {} };
</script>
<div id="content"></div>
