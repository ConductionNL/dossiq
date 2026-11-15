<?php

/**
 * Dossiq ZtcHelperFactory
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Zgw
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Kernel\Contracts\ZgwRuleResultsInterface;
use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ZtcCrossReferenceEnricherInterface;
use OCA\Dossiq\Kernel\Contracts\ZtcTypeNameResolverInterface;
use OCA\Dossiq\Kernel\Contracts\ZtcUrlValidityFilterInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the ZTC and DRC helpers the ZGW controllers use, so the controllers name
 * the helpers' contracts rather than their implementations.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural factory, no functional requirement.
 */
final class ZtcHelperFactory {
	/**
	 * The ZTC type-name resolver.
	 *
	 * @param ZgwServiceInterface $zgwService The ZGW service.
	 *
	 * @return ZtcTypeNameResolverInterface
	 *
	 * @spec exclude Structural factory, no functional requirement.
	 */
	public function ztcTypeNameResolver(ZgwServiceInterface $zgwService): ZtcTypeNameResolverInterface {
		return new ZtcTypeNameResolver(zgwService: $zgwService);
	}//end ztcTypeNameResolver()

	/**
	 * The ZTC cross-reference enricher.
	 *
	 * @param ZgwServiceInterface $zgwService The ZGW service.
	 *
	 * @return ZtcCrossReferenceEnricherInterface
	 *
	 * @spec exclude Structural factory, no functional requirement.
	 */
	public function ztcCrossReferenceEnricher(ZgwServiceInterface $zgwService): ZtcCrossReferenceEnricherInterface {
		return new ZtcCrossReferenceEnricher(zgwService: $zgwService);
	}//end ztcCrossReferenceEnricher()

	/**
	 * The ZTC URL validity filter.
	 *
	 * @param ZgwServiceInterface $zgwService The ZGW service.
	 *
	 * @return ZtcUrlValidityFilterInterface
	 *
	 * @spec exclude Structural factory, no functional requirement.
	 */
	public function ztcUrlValidityFilter(ZgwServiceInterface $zgwService): ZtcUrlValidityFilterInterface {
		return new ZtcUrlValidityFilter(zgwService: $zgwService);
	}//end ztcUrlValidityFilter()

	/**
	 * The ZGW rule-result builder.
	 *
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return ZgwRuleResultsInterface
	 *
	 * @spec exclude Structural factory, no functional requirement.
	 */
	public function zgwRuleResults(LoggerInterface $logger): ZgwRuleResultsInterface {
		return new ZgwRuleResults(logger: $logger);
	}//end zgwRuleResults()
}//end class
