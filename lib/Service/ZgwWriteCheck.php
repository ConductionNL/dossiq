<?php

/**
 * One ZGW write on its way through the cross-cutting guards
 *
 * The resource, the action, the request body and the facts the guards read
 * about the stored object and its parent, carried as one run through the
 * steps of {@see \OCA\Dossiq\Service\ZgwBusinessRulesService::validate()}.
 * The catalogi concept rules enrich the body in place, so later steps see
 * the body as it will be saved.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The state of one write check; it never leaves the rules service that built it.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
final class ZgwWriteCheck {

	/**
	 * The ZGW API group (e.g. 'zaken', 'catalogi').
	 *
	 * @var string
	 */
	public string $zgwApi = '';

	/**
	 * The ZGW resource name (e.g. 'zaaktypen').
	 *
	 * @var string
	 */
	public string $resource = '';

	/**
	 * The action ('create', 'update', 'patch', 'destroy').
	 *
	 * @var string
	 */
	public string $action = '';

	/**
	 * The request body, enriched by the concept rules.
	 *
	 * @var array
	 */
	public array $body = [];

	/**
	 * The stored object, for update, patch and destroy.
	 *
	 * @var array|null
	 */
	public ?array $existingObject = null;

	/**
	 * The OpenRegister ObjectService the rules read with.
	 *
	 * @var object|null
	 */
	public ?object $objectService = null;

	/**
	 * The mapping config.
	 *
	 * @var array|null
	 */
	public ?array $mappingConfig = null;

	/**
	 * Whether the parent zaaktype is a draft (ztc-010).
	 *
	 * @var boolean|null
	 */
	public ?bool $parentCaseTypeDraft = null;

	/**
	 * Whether the (parent) zaak is closed (zrc-007).
	 *
	 * @var boolean|null
	 */
	public ?bool $caseClosed = null;

	/**
	 * Whether the consumer holds the geforceerd-bijwerken scope.
	 *
	 * @var boolean
	 */
	public bool $hasGeforceerd = true;
}//end class
