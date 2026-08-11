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
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Quote\Model\QuoteIdMaskFactory;
use ParadoxLabs\CyberSource\Model\Config\CheckoutProvider;
use ParadoxLabs\CyberSource\Model\Config\Config;
use ParadoxLabs\CyberSourceHyvaCheckout\ViewModel\PaymentForm;
use Throwable;

class CheckoutTemplate extends Template
{
    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \ParadoxLabs\CyberSourceHyvaCheckout\ViewModel\PaymentForm $paymentForm
     * @param \ParadoxLabs\CyberSource\Model\Config\CheckoutProvider $configProvider
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Quote\Model\QuoteIdMaskFactory $quoteIdMaskFactory
     * @param array $data
     */
    public function __construct(
        Context $context,
        protected PaymentForm $paymentForm,
        protected CheckoutProvider $configProvider,
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
     * Get payment form config: shared checkout-provider keys plus the Hyva-only payer-auth
     * transport keys (the shared provider stays storefront-agnostic).
     */
    public function getConfig(): array
    {
        $config = $this->configProvider->getConfig();
        $config = $config['payment'][ $this->getMethodCode() ] ?? [];

        $config['payerAuthEndpoint'] = $this->getPayerAuthEndpoint();
        $config['payerAuthChallengeUrl'] = $this->getUrl('pdl_cybs/payerauth/challenge');

        return $config;
    }

    /**
     * Get the payer-auth REST endpoint prefix, ending in '/payer-auth/'; the client appends the
     * action (setup|authenticate|finalize).
     *
     * Store-code-qualified for multi-store. Guests address guest-carts by the masked id LOADED
     * for the session quote — never created here, mirroring core DefaultConfigProvider. Null
     * when there is no addressable cart; the client then fails visibly instead of skipping auth.
     */
    protected function getPayerAuthEndpoint(): ?string
    {
        try {
            $store = $this->_storeManager->getStore();
            $quote = $this->checkoutSession->getQuote();

            if (!$quote->getId()) {
                return null;
            }

            $restBase = $store->getBaseUrl(UrlInterface::URL_TYPE_WEB)
                . 'rest/' . $store->getCode() . '/V1';

            if ($quote->getCustomer()->getId()) {
                return $restBase . '/carts/mine/paradoxlabs-cybersource/payer-auth/';
            }

            $maskedId = $this->quoteIdMaskFactory->create()
                ->load((int)$quote->getId(), 'quote_id')
                ->getMaskedId();

            if (empty($maskedId)) {
                return null;
            }

            return $restBase . '/guest-carts/' . $maskedId . '/paradoxlabs-cybersource/payer-auth/';
        } catch (Throwable) {
            // No usable session/store context (rendered outside checkout); null fails visibly
            // client-side.
            return null;
        }
    }
}
