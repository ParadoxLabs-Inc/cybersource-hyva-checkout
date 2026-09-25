<?php

declare(strict_types=1);

namespace ParadoxLabs\CyberSourceHyvaCheckout\Test\Unit\Magewire\Payment;

use Hyva\Checkout\Model\Magewire\Component\Evaluation\Validation;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use ParadoxLabs\CyberSourceHyvaCheckout\Magewire\Payment\CyberSource;
use ParadoxLabs\CyberSourceHyvaCheckout\ViewModel\PaymentForm;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Block\Form\Cc;
use ParadoxLabs\TokenBase\Model\Card;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rakit\Validation\Validator;

/**
 * Unit tests for the CyberSource Magewire payment component
 */
class CyberSourceTest extends TestCase
{
    private CyberSource $component;
    private CheckoutSession|MockObject $checkoutSession;
    private CardRepositoryInterface|MockObject $cardRepository;
    private PaymentForm|MockObject $formViewModel;
    private Quote|MockObject $quote;
    private QuotePayment|MockObject $payment;

    protected function setUp(): void
    {
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->cardRepository = $this->createMock(CardRepositoryInterface::class);
        $this->formViewModel = $this->createMock(PaymentForm::class);

        $this->payment = $this->createMock(QuotePayment::class);

        // getCustomerId is magic (no concrete declaration on Quote); stub getData() and let the
        // real DataObject::__call route it there.
        $this->quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getPayment'])
            ->getMock();
        $this->quote->method('getPayment')->willReturn($this->payment);
        $this->checkoutSession->method('getQuote')->willReturn($this->quote);

        $this->component = new CyberSource(
            new Validator(),
            $this->checkoutSession,
            $this->cardRepository,
            $this->formViewModel,
        );
    }

    public function testMountLoadsStoredCardsFromFormBlock(): void
    {
        $card = $this->buildCard('hash123', 'Visa x-1111', 'VI');

        $formBlock = $this->createMock(Cc::class);
        $formBlock->method('getStoredCards')->willReturn([$card]);

        $this->formViewModel->method('getFormBlock')
            ->with('paradoxlabs_cybersource')
            ->willReturn($formBlock);

        $this->component->mount();

        $this->assertArrayHasKey('hash123', $this->component->storedCards);
        $this->assertSame('Visa x-1111', $this->component->storedCards['hash123']['label']);
    }

    public function testBootAddsQuoteAssignedCardOfOwnMethodAndCustomer(): void
    {
        $this->payment->method('getData')->with('tokenbase_id')->willReturn('5');
        $this->quote->method('getData')->willReturnMap([['customer_id', null, 3]]);

        $card = $this->buildCard('hash456', 'MC x-4444', 'MC', 'paradoxlabs_cybersource', 3);

        $this->cardRepository->method('getById')->with('5')->willReturn($card);

        $this->component->boot();

        $this->assertArrayHasKey('hash456', $this->component->storedCards);
    }

    public function testBootSkipsCardBelongingToAnotherMethod(): void
    {
        $this->payment->method('getData')->with('tokenbase_id')->willReturn('5');
        $this->quote->method('getData')->willReturnMap([['customer_id', null, 3]]);

        $card = $this->buildCard('hash456', 'MC x-4444', 'MC', 'paradoxlabs_stripe', 3);

        $this->cardRepository->method('getById')->willReturn($card);

        $this->component->boot();

        $this->assertSame([], $this->component->storedCards);
    }

    public function testBootSkipsCardBelongingToAnotherCustomer(): void
    {
        $this->payment->method('getData')->with('tokenbase_id')->willReturn('5');
        $this->quote->method('getData')->willReturnMap([['customer_id', null, 3]]);

        $card = $this->buildCard('hash456', 'MC x-4444', 'MC', 'paradoxlabs_cybersource', 99);

        $this->cardRepository->method('getById')->willReturn($card);

        $this->component->boot();

        $this->assertSame([], $this->component->storedCards);
    }

    public function testBootSwallowsRepositoryErrorForDeletedCard(): void
    {
        $this->payment->method('getData')->with('tokenbase_id')->willReturn('5');

        $this->cardRepository->method('getById')
            ->willThrowException(new \Magento\Framework\Exception\NoSuchEntityException(__('No such card')));

        $this->component->boot();

        $this->assertSame([], $this->component->storedCards);
    }

    public function testBootWithNoAssignedCardNeverQueriesRepository(): void
    {
        $this->payment->method('getData')->with('tokenbase_id')->willReturn(null);

        $this->cardRepository->expects($this->never())->method('getById');

        $this->component->boot();

        $this->assertSame([], $this->component->storedCards);
    }

    /**
     * Magewire 1.x discards method return values, so the browser can only receive the quote
     * total through a dispatched browser event.
     */
    public function testLoadQuoteTotalDispatchesFormattedTotalAsBrowserEvent(): void
    {
        $this->quote->method('getData')->willReturnMap([
            ['base_grand_total', null, 30.5],
        ]);

        $this->component->loadQuoteTotal();

        $this->assertSame(
            [
                [
                    'event' => 'paradoxlabs_cybersourceQuoteTotal',
                    'data' => [
                        'total' => '30.5000',
                    ],
                ],
            ],
            $this->component->getBrowserEvents()
        );
    }

    public function testEvaluateCompletionRegistersMethodValidator(): void
    {
        $validation = $this->createMock(Validation::class);

        $resultFactory = $this->createMock(EvaluationResultFactory::class);
        $resultFactory->expects($this->once())
            ->method('createValidation')
            ->with('validateparadoxlabs_cybersource')
            ->willReturn($validation);

        // No failure result: the client validator narrates every rejection itself, and a
        // cancelled 3DS challenge must stay silent — a generic message would double-message both.
        $validation->expects($this->never())
            ->method('withFailureResult');

        $this->assertSame($validation, $this->component->evaluateCompletion($resultFactory));
    }

    /**
     * Build a stored-card mock. getTypeInstance() returns the same mock, matching Card behavior.
     */
    private function buildCard(
        string $hash,
        string $label,
        string $type,
        string $method = 'paradoxlabs_cybersource',
        int $customerId = 0,
    ): Card|MockObject {
        $card = $this->createMock(Card::class);
        $card->method('getTypeInstance')->willReturn($card);
        $card->method('getHash')->willReturn($hash);
        $card->method('getLabel')->willReturn($label);
        $card->method('getType')->willReturn($type);
        $card->method('getMethod')->willReturn($method);
        $card->method('getCustomerId')->willReturn($customerId);
        $card->method('getAdditional')->willReturnMap([
            ['cc_bin', '411111'],
            ['cc_last4', '1111'],
        ]);

        return $card;
    }
}
