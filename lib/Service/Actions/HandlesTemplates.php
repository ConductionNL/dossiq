<?php

/**
 * Procest Automatic Action Template Trait
 *
 * Lightweight Mustache-flavoured `{{path.to.field}}` template renderer
 * shared by every built-in action handler. Deliberately minimal — no
 * conditionals, no loops, no helpers — to keep the surface predictable
 * for admins. The rendering MUST be deterministic so the dry-run preview
 * exactly matches the live execution output.
 *
 * @category Service
 * @package  OCA\Procest\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Actions;

/**
 * Shared template + recipient resolution helpers.
 */
trait HandlesTemplates
{
    /**
     * Render a `{{path}}` template against a case context.
     *
     * Variable lookups are dotted (`case.indiener.naam`). Unknown paths are
     * replaced with an empty string — never the original placeholder — to
     * avoid leaking template syntax into user-facing output.
     *
     * @param string $template Raw template string.
     * @param array  $case     The case object — exposed under the `case`
     *                         root scope.
     *
     * @return string
     */
    protected function renderTemplate(string $template, array $case): string
    {
        if ($template === '' || str_contains($template, '{{') === false) {
            return $template;
        }

        $context = ['case' => $case];

        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            static function (array $match) use ($context): string {
                $path   = explode('.', $match[1]);
                $cursor = $context;
                foreach ($path as $segment) {
                    if (is_array($cursor) === true && array_key_exists($segment, $cursor) === true) {
                        $cursor = $cursor[$segment];
                        continue;
                    }

                    return '';
                }

                if (is_scalar($cursor) === true) {
                    return (string) $cursor;
                }

                return '';
            },
            $template
        );
    }//end renderTemplate()

    /**
     * Resolve a recipient reference to an email address or user identifier.
     *
     * Two reference shapes are supported in V1:
     *  - `indiener` / `behandelaar` / `<role-slug>` — a role-style key
     *    looked up under `case.<key>.email` first, then `case.<key>`.
     *  - `email:<address>` — a literal email address.
     *
     * Unknown references return an empty string. Callers MUST treat an empty
     * result as a missing recipient and return a static error.
     *
     * @param string $recipientRef Reference key or `email:foo@bar` literal.
     * @param array  $case         Case object used for role-based lookup.
     *
     * @return string
     */
    protected function resolveRecipient(string $recipientRef, array $case): string
    {
        if ($recipientRef === '') {
            return '';
        }

        if (str_starts_with($recipientRef, 'email:') === true) {
            return substr($recipientRef, 6);
        }

        $value = ($case[$recipientRef] ?? null);
        if (is_array($value) === true) {
            return (string) ($value['email'] ?? '');
        }

        if (is_scalar($value) === true) {
            return (string) $value;
        }

        return '';
    }//end resolveRecipient()
}//end trait
