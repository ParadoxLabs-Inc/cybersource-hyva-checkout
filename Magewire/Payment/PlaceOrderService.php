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

namespace ParadoxLabs\CyberSourceHyvaCheckout\Magewire\Payment;

use Hyva\Checkout\Model\Magewire\Payment\AbstractPlaceOrderService;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Model\Quote;
use ParadoxLabs\CyberSource\Model\Config\Config;

class PlaceOrderService extends AbstractPlaceOrderService
{
    /**
     * Unified Checkout additional_data contract: exactly one of {transient_token, card_id}
     * populated per submit, plus the stored-card CCV and the save-card flag.
     */
    private const ALLOWED_KEYS
        = [
            'card_id' => null,
            'transient_token' => null,
            'cc_cid' => null,
            'save' => null,
        ];

    /**
     * Assign the client payment data to the quote payment, then place the order.
     *
     * importData() (not addData()) so the payment_method_assign_data observer chain runs —
     * that is what copies transient_token into additional_information for the UC auth seam
     * and resolves card_id into tokenbase_id.
     *
     * @throws CouldNotSaveException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function placeOrder(Quote $quote): int
    {
        $paymentData = (array)$this->getData()->getPayment();

        // Only pass through known allowed values, to prevent parameter injection
        $knownPaymentData = array_intersect_key(
            $paymentData,
            self::ALLOWED_KEYS,
        );

        $quote->getPayment()->importData([
            PaymentInterface::KEY_METHOD => Config::CODE,
            PaymentInterface::KEY_ADDITIONAL_DATA => $knownPaymentData,
        ]);

        return parent::placeOrder($quote);
    }
}
