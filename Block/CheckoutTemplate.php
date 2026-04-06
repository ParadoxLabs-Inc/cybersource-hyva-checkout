<?php declare(strict_types=1);
/**
 * Copyright © 2023-present ParadoxLabs, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * Need help? Try our knowledgebase and support system:
 *
 * @link https://support.paradoxlabs.com
 */

namespace ParadoxLabs\CyberSourceHyvaCheckout\Block;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Quote\Model\QuoteIdMaskFactory;
use ParadoxLabs\CyberSource\Model\Config\CheckoutProvider;
use ParadoxLabs\CyberSource\Model\Config\Config;
use ParadoxLabs\CyberSourceHyvaCheckout\ViewModel\PaymentForm;
use ParadoxLabs\TokenBase\Gateway\Validator\CreditCard\Types;

class CheckoutTemplate extends Template
{
    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \ParadoxLabs\CyberSourceHyvaCheckout\ViewModel\PaymentForm $paymentForm
     * @param \ParadoxLabs\CyberSource\Model\Config\CheckoutProvider $configProvider
     * @param \ParadoxLabs\TokenBase\Gateway\Validator\CreditCard\Types $ccTypes
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Quote\Model\QuoteIdMaskFactory $quoteIdMaskFactory
     * @param array $data
     */
    public function __construct(
        Context $context,
        protected PaymentForm $paymentForm,
        protected CheckoutProvider $configProvider,
        protected Types $ccTypes,
        protected CheckoutSession $checkoutSession,
        protected QuoteIdMaskFactory $quoteIdMaskFactory,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get payment method code for the active/relevant method
     */
    public function getMethodCode(): string
    {
        return $this->getData('method_code') ?? Config::CODE;
    }

    /**
     * Get payment form config object
     */
    public function getConfig(): array
    {
        $config = $this->configProvider->getConfig();
        $methodConfig = $config['payment'][ $this->getMethodCode() ] ?? [];

        $quote = $this->checkoutSession->getQuote();
        $methodConfig['isGuest'] = !$quote->getCustomerId();
        $methodConfig['cartId'] = $this->getMaskedCartId((int)$quote->getId());
        $methodConfig['successUrl'] = $this->getUrl('checkout/onepage/success');

        return $methodConfig;
    }

    /**
     * Get masked cart ID for REST API guest cart access
     */
    protected function getMaskedCartId(int $quoteId): string
    {
        try {
            $quoteIdMask = $this->quoteIdMaskFactory->create()->load($quoteId, 'quote_id');

            if ($quoteIdMask->getMaskedId()) {
                return (string)$quoteIdMask->getMaskedId();
            }
        } catch (\Throwable) {
            // Fall through
        }

        return '';
    }

    /**
     * Get credit card types, by code
     */
    public function getCcTypes(): array
    {
        $types       = $this->ccTypes->getTypes();
        $typesByCode = [];
        foreach ($types as $type) {
            $typesByCode[ $type['type'] ] = $type;
        }

        return $typesByCode;
    }
}
