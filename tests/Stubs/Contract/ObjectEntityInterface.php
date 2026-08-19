<?php

/**
 * Test stub for OpenRegister's ObjectEntityInterface.
 *
 * 🔴 Why this file has to exist: `tests/Stubs/Db/ObjectEntity.php` already
 * declared `implements \OCA\OpenRegister\Contract\ObjectEntityInterface`, and
 * nothing anywhere stubbed that interface. It worked only because CI clones
 * `ConductionNL/openregister` (the `additional-apps` workflow input), so the
 * REAL interface is on the autoloader there. On a dev instance it is not, and
 * 40 tests died with `Interface ... not found` — tests that CI reported green.
 *
 * A test suite that only runs inside CI is a suite nobody runs before pushing.
 *
 * ⚠️ Mirrors the real interface's method surface exactly (six accessors, as of
 * openregister `lib/Contract/ObjectEntityInterface.php`). A stub that declares
 * fewer methods than the real type is worse than none: it lets an incompatible
 * implementation pass locally and fail only in CI, which is the same asymmetry
 * this file exists to remove. Keep them in sync.
 *
 * @category Stub
 * @package  OCA\OpenRegister\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

use JsonSerializable;

/**
 * Stub of OpenRegister's ObjectEntityInterface for unit tests.
 *
 * ⚠️ `extends JsonSerializable` is NOT decoration — the real interface extends
 * it, and procest's own tests mock `jsonSerialize()`. I wrote this stub with
 * the six accessors and without the parent, and four tests immediately failed
 * with "Trying to configure method jsonSerialize which ... does not exist".
 * That is this file's own warning coming true within minutes: a stub narrower
 * than the real type is worse than no stub.
 */
interface ObjectEntityInterface extends JsonSerializable {

	/**
	 * The object's uuid.
	 *
	 * @return string|null The uuid, or null when unsaved.
	 */
	public function getUuid(): ?string;

	/**
	 * The object's own data.
	 *
	 * @return array<string, mixed> The object payload.
	 */
	public function getObject(): array;

	/**
	 * The register this object belongs to.
	 *
	 * @return string|null The register id, or null.
	 */
	public function getRegister(): ?string;

	/**
	 * The schema this object validates against.
	 *
	 * @return string|null The schema id, or null.
	 */
	public function getSchema(): ?string;

	/**
	 * The owning organisation.
	 *
	 * @return string|null The organisation id, or null.
	 */
	public function getOrganisation(): ?string;

	/**
	 * The owning user.
	 *
	 * @return string|null The owner uid, or null.
	 */
	public function getOwner(): ?string;

}//end interface
