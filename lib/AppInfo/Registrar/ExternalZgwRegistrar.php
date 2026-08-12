<?php

/**
 * Procest external-ZGW / ZTC registrar.
 *
 * Binds the cross-municipality ZGW hand-off seam and the ZTC / Catalogi-API
 * zaaktype-resolution seam. Both are dormant log-only aliases: procest declares
 * the ports so downstream deployments can override them, but ships no live
 * client.
 *
 * @category AppInfo
 * @package  OCA\Procest\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\AppInfo\Registrar;

use OCA\Procest\Service\External\Zgw\LogZgwExternalAdapter;
use OCA\Procest\Service\External\Zgw\ZgwExternalAdapterInterface;
use OCA\Procest\Service\External\Ztc\LogZtcCatalogiAdapter;
use OCA\Procest\Service\External\Ztc\ZtcCatalogiAdapterInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the dormant external-ZGW and ZTC / Catalogi-API client aliases.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class ExternalZgwRegistrar {
	/**
	 * Register the external-ZGW and ZTC aliases.
	 *
	 * TMLO metadata building + e-Depot submission adapter seams are retired
	 * (migrate-archival-to-or, ADR-022): OpenRegister's TmloService builds
	 * TMLO/MDTO metadata from schema config and its Edepot/Transport seam owns
	 * submission. Procest contributes the mapping declaratively (tmloDefaults).
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(
			ZgwExternalAdapterInterface::class,
			LogZgwExternalAdapter::class
		);
		$context->registerServiceAlias(
			ZtcCatalogiAdapterInterface::class,
			LogZtcCatalogiAdapter::class
		);
	}//end register()
}//end class
