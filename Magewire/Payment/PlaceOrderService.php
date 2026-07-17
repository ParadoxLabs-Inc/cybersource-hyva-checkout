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
     * The addData() that follows is load-bearing, not redundant: when Hyva passes the quote
     * payment into CartManagement::placeOrder(), QuoteManagement re-runs
     * importData($payment->getData()) (QuoteManagement::placeOrderRun). Our assign observer
     * CLEARS additional_information.transient_token whenever the incoming data carries no
     * top-level transient_token — which is exactly the post-assign state — so without the
     * re-staged keys that second import would wipe the token it just stored.
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

        $payment = $quote->getPayment();
        $payment->importData([
            PaymentInterface::KEY_METHOD => Config::CODE,
            PaymentInterface::KEY_ADDITIONAL_DATA => $knownPaymentData,
        ]);

        // Re-stage the allowed keys as raw payment data so the re-import re-asserts them
        // through the observer chain instead of clearing them (see docblock).
        $payment->addData($knownPaymentData);

        return parent::placeOrder($quote);
    }
}
