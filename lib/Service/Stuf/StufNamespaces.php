<?php

/**
 * Dossiq StufNamespaces.
 *
 * The StUF XML namespaces dossiq's StUF provider (/api/stuf/cases and
 * /api/stuf/persons) answers in. They lived on StufMessageBuilder, which built
 * the outbound messages integriq now sends (decision 193).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/stuf-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Stuf;

/**
 * StUF 3.01 / ZKN 3.10 / BG 3.10 namespaces.
 *
 * @spec openspec/specs/stuf-integration/spec.md
 */
final class StufNamespaces {

	/**
	 * StUF 3.01 core.
	 *
	 * @var string
	 */
	public const NS_STUF = 'http://www.egem.nl/StUF/StUF0301';

	/**
	 * Sector model ZKN 3.10.
	 *
	 * @var string
	 */
	public const NS_ZKN = 'http://www.egem.nl/StUF/sector/zkn/0310';

	/**
	 * Sector model BG 3.10.
	 *
	 * @var string
	 */
	public const NS_BG = 'http://www.egem.nl/StUF/sector/bg/0310';

	/**
	 * SOAP 1.1 envelope.
	 *
	 * @var string
	 */
	public const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';

	/**
	 * XML Schema instance.
	 *
	 * @var string
	 */
	public const NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}//end __construct()
}//end class
