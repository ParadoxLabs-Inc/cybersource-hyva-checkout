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

use Exception;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Hyva\Checkout\Model\Magewire\Payment\AbstractPlaceOrderService;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Model\Quote;
use Magewirephp\Magewire\Component;
use ParadoxLabs\CyberSource\Model\Config\Config;

class PlaceOrderService extends AbstractPlaceOrderService
{
    /**
     * @var Exception|null
     */
    protected ?Exception $placeOrderException = null;

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

    /**
     * Accept a place-order failure instead of rethrowing.
     *
     * The default rethrow aborts the Magewire request with a bare {message, code} JSON response:
     * the order:place:{method}:error browser events the processor queued before calling this are
     * only serialized into response effects at dehydrate, so they are silently discarded — the
     * client-side drop-in reset (spent transient token / capture context) never runs — and in
     * production mode the customer sees a generic "page refresh" dialog instead of the decline
     * message.
     *
     * Buffering the exception lets the request complete normally, which delivers the queued error
     * events; evaluateCompletion() surfaces the real (sanitized) message through the standard
     * messenger, and canRedirect() blocks the unconditional success-page redirect the processor
     * would otherwise push for the failed attempt.
     */
    public function handleException(Exception $exception, Component $component, Quote $quote): void
    {
        $this->placeOrderException = $exception;
    }

    /**
     * Report the place-order outcome: default success behavior when an order was placed, an
     * error message with the failure reason otherwise.
     *
     * Message sanitization mirrors the processor's event detail: a LocalizedException message is
     * safe to show; anything else gets the generic text so raw exception detail never leaks. Never
     * returns the parent's unconditional success for a failed attempt.
     */
    public function evaluateCompletion(
        EvaluationResultFactory $resultFactory,
        int|null $orderId = null,
    ): EvaluationResultInterface {
        if ($this->placeOrderException === null) {
            return parent::evaluateCompletion($resultFactory, $orderId);
        }

        $message = $this->placeOrderException instanceof LocalizedException
            ? $this->placeOrderException->getMessage()
            : (string)__('Something went wrong while processing your order. Please try again.');

        $errorMessage = $resultFactory->createErrorMessage();
        $errorMessage->withMessage($message);
        $errorMessage->withVisibilityDuration(7500);

        return $errorMessage;
    }

    /**
     * Block the processor's success-page redirect after a failed attempt, keeping the customer on
     * checkout in a retryable state.
     */
    public function canRedirect(): bool
    {
        return $this->placeOrderException === null;
    }
}
