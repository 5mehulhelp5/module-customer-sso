<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model;

/**
 * Decides whether native email/password login is off ("Keep Password Login" = No).
 *
 * Only while SSO is live (enabled, an active provider, a client id and a valid discovery
 * URL), so a mis-set toggle on an unconfigured or broken install can never lock shoppers
 * out with no way in — e.g. Generic OIDC selected with an inherited blank Issuer URL.
 */
class PasswordLoginPolicy
{
    /**
     * @param Config $config
     * @param ActiveProviderResolver $activeProviderResolver
     */
    public function __construct(
        private readonly Config $config,
        private readonly ActiveProviderResolver $activeProviderResolver
    ) {
    }

    /**
     * Whether SSO is live and the admin turned native password login off.
     *
     * @param int|string|null $storeId null = current store
     * @return bool
     */
    public function isPasswordLoginDisabled($storeId = null): bool
    {
        return $this->config->isEnabled($storeId)
            && !$this->config->isPasswordLoginAllowed($storeId)
            && $this->isSsoStartable($storeId);
    }

    /**
     * Whether a sign-in could start: mirrors the preconditions of AuthorizationStarter.
     *
     * The preset reads its own config for the current store, as on sign-in start.
     *
     * @param int|string|null $storeId
     */
    private function isSsoStartable($storeId): bool
    {
        $preset = $this->activeProviderResolver->getActive($storeId);
        $clientId = $this->config->getClientId($storeId);
        if ($preset === null || $clientId === null) {
            return false;
        }

        try {
            $preset->buildDiscoveryUrl(['client_id' => $clientId]);
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }
}
