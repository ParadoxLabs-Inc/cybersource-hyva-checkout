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

use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magewirephp\Magewire\Component\Form;
use ParadoxLabs\CyberSourceHyvaCheckout\ViewModel\PaymentForm;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;
use ParadoxLabs\TokenBase\Block\Form\Cc;
use Rakit\Validation\Validator;

/**
 * Magewire component for the CyberSource Unified Checkout payment form.
 *
 * Card entry happens client-side in the UC drop-in (see scripts.phtml); this component owns the
 * stored-card list, the place-order evaluation, and the server-side quote data the client needs
 * to keep the capture context honest (grand total drift detection at submit).
 */
class CyberSource extends Form implements EvaluationInterface
{
    protected const METHOD_CODE = 'paradoxlabs_cybersource';

    /**
     * @var array
     */
    protected $loader
        = [
            'billing_address_activated' => 'Loading payment form',
            'billing_address_saved' => 'Loading payment form',
            'billing_address_submitted' => 'Loading payment form',
        ];

    /**
     * The capture context's billTo is sourced from the quote billing address, so any billing
     * change invalidates a not-yet-used context; signal the browser so the drop-in can (re)mount.
     *
     * @var string[]
     */
    protected $listeners
        = [
            'billing_address_activated' => 'triggerBillingUpdate',
            'billing_address_saved' => 'triggerBillingUpdate',
            'billing_address_submitted' => 'triggerBillingUpdate',
        ];

    /* Public component properties */
    public $storedCards = [];

    /* Protected property validation rule map */
    protected $rules
        = [
            'storedCards' => 'array',
        ];

    /**
     * @param \Rakit\Validation\Validator $validator
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \ParadoxLabs\CyberSourceHyvaCheckout\ViewModel\PaymentForm $formViewModel
     */
    public function __construct(
        Validator $validator,
        protected CheckoutSession $checkoutSession,
        protected CardRepositoryInterface $cardRepository,
        protected PaymentForm $formViewModel,
    ) {
        parent::__construct($validator);
    }

    /**
     * Initialize component data on update
     */
    public function boot(): void
    {
        $this->loadSelectedCard();
    }

    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function mount(): void
    {
        $this->loadStoredCards();
    }

    /**
     * Update component selected card based on the quote's assigned stored card
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function loadSelectedCard(): void
    {
        $payment = $this->getQuote()->getPayment();

        if ($payment->getData('tokenbase_id') !== null) {
            $card = $this->cardRepository->getById($payment->getData('tokenbase_id'));

            if ($card->getMethod() === static::METHOD_CODE
                && (int)$card->getCustomerId() === (int)$this->getQuote()->getCustomerId()) {
                $this->addStoredCardToList($card);
            }
        }
    }

    /**
     * Notify the browser that the billing address changed, so the drop-in can mount or re-mint
     * its capture context against the updated billTo.
     */
    public function triggerBillingUpdate(): void
    {
        $this->dispatchBrowserEvent(static::METHOD_CODE . 'BillingUpdated', []);
    }

    /**
     * Get the quote base grand total, for client-side capture-context amount drift detection.
     *
     * The capture mandate amount is baked into the capture context (and any transient token minted
     * against it), so the client compares this at mount time and again at submit; a mismatch forces
     * re-entry against a freshly priced context.
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getQuoteTotal(): string
    {
        return number_format((float)$this->getQuote()->getBaseGrandTotal(), 4, '.', '');
    }

    /**
     * Get the current user's active quote
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getQuote(): CartInterface
    {
        return $this->checkoutSession->getQuote();
    }

    /**
     * Determine whether checkout completion is allowed
     */
    public function evaluateCompletion(EvaluationResultFactory $factory): EvaluationResultInterface
    {
        $validationError = $factory->createErrorMessage();
        $validationError->withMessage('There\'s an issue with your payment details. Please check the payment form.');
        $validationError->withVisibilityDuration(5000);

        $validation = $factory->createValidation('validate' . static::METHOD_CODE);
        $validation->withFailureResult($validationError);

        return $validation;
    }

    /**
     * Get the active payment method instance
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getMethod(): MethodInterface
    {
        return $this->formViewModel->getMethod(static::METHOD_CODE);
    }

    /**
     * Get the active payment method form block
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getFormBlock(): Cc
    {
        return $this->formViewModel->getFormBlock(static::METHOD_CODE);
    }

    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function loadStoredCards(): void
    {
        /** @var \ParadoxLabs\TokenBase\Model\Card $card */
        foreach ($this->getFormBlock()->getStoredCards() as $card) {
            $this->addStoredCardToList($card);
        }
    }

    protected function addStoredCardToList(CardInterface $card): void
    {
        $card = $card->getTypeInstance();

        $this->storedCards[ $card->getHash() ] = [
            'hash' => $card->getHash(),
            'label' => $card->getLabel(),
            'type' => $card->getType(),
            'cc_bin' => $card->getAdditional('cc_bin'),
            'cc_last4' => $card->getAdditional('cc_last4'),
        ];
    }
}
