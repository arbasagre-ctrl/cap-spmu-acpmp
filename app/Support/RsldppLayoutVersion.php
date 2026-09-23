<?php

namespace App\Support;

/**
 * The RSLDDP's official content, appraisal fields, signatories, and layout
 * remain client-confirmation-required (docs/CONFIGURATION-REGISTER.md).
 * This class is the single, code-controlled record of which layout version
 * is actually deployed - it is never Admin-editable (no SystemSetting, no
 * database row), so flipping the separate `rslddp_template_status` setting
 * to APPROVED in Administration -> Configuration can never, by itself, make
 * this still-provisional layout appear institutionally official. The
 * provisional marker is removed only once a developer actually ships the
 * client-approved layout and changes what current() returns.
 *
 * Resolved through the container (not a bare constant) so tests can bind a
 * fake implementation to exercise the "approved layout deployed" case
 * without ever mutating this class's real value at runtime.
 */
class RsldppLayoutVersion
{
    public const PROVISIONAL_PLACEHOLDER = 'PROVISIONAL_V1';

    public function current(): string
    {
        return self::PROVISIONAL_PLACEHOLDER;
    }

    public function isApprovedLayout(): bool
    {
        return $this->current() !== self::PROVISIONAL_PLACEHOLDER;
    }
}
