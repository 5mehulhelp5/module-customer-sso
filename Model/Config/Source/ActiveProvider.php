<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model\Config\Source;

use DmLab\CustomerSso\Model\PresetRegistry;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;

/**
 * Active-provider dropdown source for the storefront SSO config field.
 *
 * Options are built from the {@see PresetRegistry} — i.e. whatever provider
 * plugins registered their preset — so the list grows as plugins are installed
 * without this core knowing any IdP.
 */
class ActiveProvider implements OptionSourceInterface
{
    /**
     * @param PresetRegistry $presetRegistry
     * @param array<string,string|Phrase> $optionLabels Provider code => dropdown label overriding the preset label.
     */
    public function __construct(
        private readonly PresetRegistry $presetRegistry,
        private readonly array $optionLabels = []
    ) {
    }

    /**
     * @inheritDoc
     *
     * @return array<int,array{value:string,label:Phrase|string}>
     */
    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('-- Please Select --')]];
        foreach ($this->presetRegistry->getAll() as $preset) {
            $code = $preset->getCode();
            $options[] = ['value' => $code, 'label' => $this->optionLabels[$code] ?? $preset->getLabel()];
        }

        return $options;
    }
}
