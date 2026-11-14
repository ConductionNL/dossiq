<?php

/**
 * Contract of ConfiguredRegistryService
 *
 * The public surface of ConfiguredRegistryService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
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

namespace OCA\Dossiq\Kernel\Contracts;

use RuntimeException;

/**
 * Contract of ConfiguredRegistryService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConfiguredRegistryServiceInterface {

	/**
	 * List every object of a configured schema.
	 *
	 * Degrades to an empty array when OpenRegister or the schema is
	 * unconfigured, matching the rest of dossiq's read paths.
	 *
	 * @param string $schemaConfigKey Config key naming the schema.
	 * @param array<string, mixed> $filters Optional object-field filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function list(string $schemaConfigKey, array $filters = []): array;

	/**
	 * Record what the saved registry means for the Store card.
	 *
	 * The Store's own settings do not go through the generic settings save, so
	 * the Integrations page would never hear about them. This lives here rather
	 * than in the controller because the question it answers — is a registry
	 * configured — is this service's question, and because the controller is at
	 * the coupling ceiling: one more collaborator there is a PHPMD failure, and
	 * a `@SuppressWarnings` would buy the same code a worse home.
	 *
	 * A registry with no address resolves nothing, whatever else is filled in,
	 * so the URL is the whole requirement.
	 *
	 * @param string $registryUrl The saved registry URL.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function recordStoreStatus(string $registryUrl): void;

	/**
	 * Create or update a single object.
	 *
	 * ⚠️ `saveObject()` is PUT-semantic in OpenRegister — keys omitted from the
	 * payload are nulled, not left alone. An update caller must therefore send
	 * the whole object, not a patch fragment.
	 *
	 * @param string $schemaConfigKey Config key naming the schema.
	 * @param array<string, mixed> $data The object payload.
	 * @param string|null $id Existing id, or null to create.
	 *
	 * @return array<string, mixed> The saved object.
	 *
	 * @throws RuntimeException When the register or schema is not configured.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function save(string $schemaConfigKey, array $data, ?string $id = null): array;
}//end interface
