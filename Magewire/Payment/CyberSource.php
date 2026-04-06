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
use ParadoxLabs\CyberSource\Model\Config\Config;
use ParadoxLabs\CyberSource\Model\Service\SecureAcceptance\FrontendRequest;
use ParadoxLabs\CyberSourceHyvaCheckout\ViewModel\PaymentForm;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;
use ParadoxLabs\TokenBase\Block\Form\Cc;
use ParadoxLabs\TokenBase\Helper\Data;
use Rakit\Validation\Validator;

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
            'getNewCard' => 'Updating payment data',
        ];

    /**
     * @var string[]
     */
    protected $listeners
        = [
            'billing_address_activated' => 'initHostedForm',
            'billing_address_saved' => 'initHostedForm',
            'billing_address_submitted' => 'initHostedForm',
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
     * @param \ParadoxLabs\CyberSource\Model\Service\SecureAcceptance\FrontendRequest $secureAcceptRequest
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \ParadoxLabs\TokenBase\Helper\Data $helper
     * @param \ParadoxLabs\CyberSourceHyvaCheckout\ViewModel\PaymentForm $formViewModel
     */
    public function __construct(
        Validator $validator,
        protected CheckoutSession $checkoutSession,
        protected FrontendRequest $secureAcceptRequest,
        protected CardRepositoryInterface $cardRepository,
        protected Data $helper,
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
     * Generate Secure Acceptance form params and dispatch to browser
     *
     * @see \ParadoxLabs\CyberSource\Model\Service\SecureAcceptance\FrontendRequest
     */
    public function initHostedForm(): void
    {
        $params = [
            'iframeAction' => $this->secureAcceptRequest->getIframeUrl(),
            'iframeParams' => $this->secureAcceptRequest->getIframeParams(),
        ];

        $this->dispatchBrowserEvent(
            static::METHOD_CODE . 'InitHostedForm',
            $params,
        );
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

    /**
     * Handle communicator failure notification from frontend
     */
    public function notifyCommunicatorFailure(): void
    {
        $this->helper->log(static::METHOD_CODE, 'ERROR: User failed to load hosted form communicator');

        $this->dispatchErrorMessage(
            __(
                'Payment gateway failed to connect. Please reload and try again. '
                . 'If the problem continues, please seek support.'
            ),
        );
    }

    /**
     * Import a newly saved card from Secure Acceptance response
     *
     * The card was already saved server-side by complete.phtml -> SecureAcceptance\Response::saveCard().
     * This method fetches it by hash and adds to the component's storedCards list.
     *
     * @param array $data Card data from postMessage (includes 'id' hash)
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getNewCard(array $data = []): void
    {
        if (empty($data['id'])) {
            return;
        }

        $card = $this->cardRepository->getByHash($data['id']);

        if ($card->getMethod() !== static::METHOD_CODE
            || (int)$card->getCustomerId() !== (int)$this->getQuote()->getCustomerId()) {
            return;
        }

        $this->addStoredCardToList($card);
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
