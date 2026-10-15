<?php

/**
 * Dossiq beschikking adapter registrar.
 *
 * Binds the beschikking archival seam to the implementation this instance
 * has. Signing is no longer a seam here: filinq signs, and dossiq asks it
 * through Service/Signing/DocumentSigningRequester (signing-through-filinq).
 *
 * The THIRD seam, template render, moved to {@see SubstitutableAdapterRegistrar}.
 * It is not a seam dossiq can satisfy: the renderer is filinq's, so the binding
 * is a config-named class rather than a class this repo ships, and it belongs
 * with the Berichtenbox seam that has the same shape.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Service\Beschikking\ArchivalAdapterInterface;
use OCA\Dossiq\Service\Beschikking\OpenRegisterArchivalAdapter;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the beschikking signing and archival adapters.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class BeschikkingAdapterRegistrar {

	/**
	 * Register the beschikking cross-app integration adapters.
	 *
	 * Background jobs are declared in appinfo/info.xml under
	 * <background-jobs>; Nextcloud auto-registers them with the IJobList.
	 * IRegistrationContext has no registerJob() method.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// Beschikking archival is repointed onto OpenRegister's declarative
		// archival pipeline (ADR-022 / migrate-archival-to-or): retention/
		// destruction are governed by x-openregister-archival on the case
		// schema; this adapter records the archival marker + Archiefwet
		// vernietigingsdatum. The former app-local MockArchivalAdapter is retired.
		$context->registerServiceAlias(ArchivalAdapterInterface::class, OpenRegisterArchivalAdapter::class);
	}//end register()
}//end class
