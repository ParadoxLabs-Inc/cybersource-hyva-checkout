<?php

declare(strict_types=1);

namespace ParadoxLabs\CyberSourceHyvaCheckout\Test\Unit\Magewire\Payment;

use Hyva\Checkout\Model\Magewire\Component\Evaluation\ErrorMessage;
use Hyva\Checkout\Model\Magewire\Component\Evaluation\Success;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Payment\DefaultOrderData;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magewirephp\Magewire\Component;
use ParadoxLabs\CyberSourceHyvaCheckout\Magewire\Payment\PlaceOrderService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Hyva Checkout PlaceOrderService
 */
class PlaceOrderServiceTest extends TestCase
{
    private PlaceOrderService $service;
    private CartManagementInterface|MockObject $cartManagement;
    private Quote|MockObject $quote;
    private QuotePayment|MockObject $payment;

    protected function setUp(): void
    {
        $this->cartManagement = $this->createMock(CartManagementInterface::class);
        $this->payment = $this->createMock(QuotePayment::class);

        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getPayment')->willReturn($this->payment);
        $this->quote->method('getId')->willReturn(7);

        $this->service = new PlaceOrderService(
            $this->cartManagement,
            new DefaultOrderData(),
        );
    }

    public function testPlaceOrderImportsWhitelistedPaymentDataAndRestages(): void
    {
        $this->service->getData()->setData([
            'payment' => [
                'transient_token' => 'jwt-abc123',
                'card_id' => '',
                'cc_cid' => '',
                'save' => 1,
            ],
        ]);

        $expected = [
            'transient_token' => 'jwt-abc123',
            'card_id' => '',
            'cc_cid' => '',
            'save' => 1,
        ];

        $this->payment->expects($this->once())
            ->method('importData')
            ->with([
                PaymentInterface::KEY_METHOD => 'paradoxlabs_cybersource',
                PaymentInterface::KEY_ADDITIONAL_DATA => $expected,
            ]);

        // Re-stage keeps the assign observer from wiping the transient token on QuoteManagement's
        // second import.
        $this->payment->expects($this->once())
            ->method('addData')
            ->with($expected);

        $this->cartManagement->expects($this->once())
            ->method('placeOrder')
            ->with(7, $this->payment)
            ->willReturn('42');

        $this->assertSame(42, $this->service->placeOrder($this->quote));
    }

    public function testPlaceOrderStripsUnknownKeysAndForcesMethod(): void
    {
        $this->service->getData()->setData([
            'payment' => [
                'method' => 'checkmo',
                'transient_token' => 'jwt-abc123',
                'tokenbase_id' => '999',
                'transaction_id' => 'txn-evil',
                'save' => 0,
            ],
        ]);

        $expected = [
            'transient_token' => 'jwt-abc123',
            'save' => 0,
        ];

        $this->payment->expects($this->once())
            ->method('importData')
            ->with([
                PaymentInterface::KEY_METHOD => 'paradoxlabs_cybersource',
                PaymentInterface::KEY_ADDITIONAL_DATA => $expected,
            ]);

        $this->payment->expects($this->once())->method('addData')->with($expected);

        $this->cartManagement->method('placeOrder')->willReturn('100');

        $this->assertSame(100, $this->service->placeOrder($this->quote));
    }

    public function testPlaceOrderPassesStoredCardWithCvv(): void
    {
        $this->service->getData()->setData([
            'payment' => [
                'card_id' => 'a1b2c3hash',
                'cc_cid' => '123',
                'save' => 1,
            ],
        ]);

        $expected = [
            'card_id' => 'a1b2c3hash',
            'cc_cid' => '123',
            'save' => 1,
        ];

        $this->payment->expects($this->once())
            ->method('importData')
            ->with([
                PaymentInterface::KEY_METHOD => 'paradoxlabs_cybersource',
                PaymentInterface::KEY_ADDITIONAL_DATA => $expected,
            ]);

        $this->payment->expects($this->once())->method('addData')->with($expected);

        $this->cartManagement->method('placeOrder')->willReturn('55');

        $this->assertSame(55, $this->service->placeOrder($this->quote));
    }

    public function testPlaceOrderWithNoPaymentDataImportsEmptyAdditionalData(): void
    {
        $this->service->getData()->setData([]);

        $this->payment->expects($this->once())
            ->method('importData')
            ->with([
                PaymentInterface::KEY_METHOD => 'paradoxlabs_cybersource',
                PaymentInterface::KEY_ADDITIONAL_DATA => [],
            ]);

        $this->payment->expects($this->once())->method('addData')->with([]);

        $this->cartManagement->method('placeOrder')->willReturn('5');

        $this->assertSame(5, $this->service->placeOrder($this->quote));
    }

    /**
     * handleException must NOT rethrow: a rethrow aborts the Magewire request with a bare
     * {message, code} JSON, discarding the order:place:{method}:error browser events the
     * processor queued just before calling it. Completing normally is what delivers them.
     */
    public function testHandleExceptionDoesNotRethrowAndBlocksRedirect(): void
    {
        $this->assertTrue($this->service->canRedirect());

        $this->service->handleException(
            new \Exception('gateway boom'),
            $this->createMock(Component::class),
            $this->quote
        );

        // Reaching this point proves no rethrow; the failed attempt must not redirect either.
        $this->assertFalse($this->service->canRedirect());
    }

    public function testEvaluateCompletionAfterLocalizedFailureShowsRealMessage(): void
    {
        $this->service->handleException(
            new LocalizedException(__('Card declined.')),
            $this->createMock(Component::class),
            $this->quote
        );

        $errorMessage = $this->createMock(ErrorMessage::class);
        $errorMessage->expects($this->once())->method('withMessage')->with('Card declined.');

        $resultFactory = $this->createMock(EvaluationResultFactory::class);
        $resultFactory->method('createErrorMessage')->willReturn($errorMessage);
        $resultFactory->expects($this->never())->method('createSuccess');

        $this->assertSame($errorMessage, $this->service->evaluateCompletion($resultFactory));
    }

    public function testEvaluateCompletionAfterGenericFailureShowsGenericMessage(): void
    {
        $this->service->handleException(
            new \RuntimeException('PDO: connection refused'),
            $this->createMock(Component::class),
            $this->quote
        );

        $errorMessage = $this->createMock(ErrorMessage::class);
        $errorMessage->expects($this->once())
            ->method('withMessage')
            ->with($this->logicalAnd(
                $this->stringContains('Something went wrong'),
                $this->logicalNot($this->stringContains('PDO'))
            ));

        $resultFactory = $this->createMock(EvaluationResultFactory::class);
        $resultFactory->method('createErrorMessage')->willReturn($errorMessage);

        $this->assertSame($errorMessage, $this->service->evaluateCompletion($resultFactory));
    }

    public function testEvaluateCompletionWithoutFailureUsesDefaultSuccess(): void
    {
        $success = $this->createMock(Success::class);

        $resultFactory = $this->createMock(EvaluationResultFactory::class);
        $resultFactory->expects($this->once())->method('createSuccess')->willReturn($success);
        $resultFactory->expects($this->never())->method('createErrorMessage');

        $this->assertSame($success, $this->service->evaluateCompletion($resultFactory, 42));
        $this->assertTrue($this->service->canRedirect());
    }
}
