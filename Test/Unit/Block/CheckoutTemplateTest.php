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

namespace ParadoxLabs\CyberSourceHyvaCheckout\Test\Unit\Block;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteIdMask;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ParadoxLabs\CyberSource\Model\Config\CheckoutProvider;
use ParadoxLabs\CyberSourceHyvaCheckout\Block\CheckoutTemplate;
use ParadoxLabs\CyberSourceHyvaCheckout\ViewModel\PaymentForm;

/**
 * Covers the Hyva-only transport keys getConfig() layers over the shared checkout provider
 * config: the payer-auth webapi endpoint prefix (carts/mine vs guest-carts resolution) and the
 * challenge wrapper URL.
 */
class CheckoutTemplateTest extends TestCase
{
    protected const CHALLENGE_URL = 'https://store.example/pdl_cybs/payerauth/challenge/';

    /**
     * @var CheckoutProvider|MockObject
     */
    protected $configProvider;

    /**
     * @var CheckoutSession|MockObject
     */
    protected $checkoutSession;

    /**
     * @var QuoteIdMaskFactory|MockObject
     */
    protected $quoteIdMaskFactory;

    /**
     * @var Quote|MockObject
     */
    protected $quote;

    /**
     * @var CustomerInterface|MockObject
     */
    protected $customer;

    /**
     * @var CheckoutTemplate
     */
    protected $block;

    protected function setUp(): void
    {
        $store = $this->createMock(Store::class);
        // Pinned to URL_TYPE_WEB: URL_TYPE_LINK doubles the store code into the REST path on
        // add-store-code-to-urls stores.
        $store->method('getBaseUrl')
            ->with(UrlInterface::URL_TYPE_WEB)
            ->willReturn('https://store.example/');
        $store->method('getCode')
            ->willReturn('default');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')
            ->willReturn($store);

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')
            ->with('pdl_cybs/payerauth/challenge')
            ->willReturn(static::CHALLENGE_URL);

        $context = $this->createMock(Context::class);
        $context->method('getStoreManager')
            ->willReturn($storeManager);
        $context->method('getUrlBuilder')
            ->willReturn($urlBuilder);

        $this->configProvider = $this->createMock(CheckoutProvider::class);
        $this->configProvider->method('getConfig')
            ->willReturn([
                'payment' => [
                    'paradoxlabs_cybersource' => [
                        'payerAuthActive' => true,
                    ],
                ],
            ]);

        $this->customer = $this->createMock(CustomerInterface::class);

        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getCustomer')
            ->willReturn($this->customer);

        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->checkoutSession->method('getQuote')
            ->willReturn($this->quote);

        $this->quoteIdMaskFactory = $this->getMockBuilder(QuoteIdMaskFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        $this->block = new CheckoutTemplate(
            $context,
            $this->createMock(PaymentForm::class),
            $this->configProvider,
            $this->checkoutSession,
            $this->quoteIdMaskFactory,
        );
    }

    /**
     * Stub the mask lookup for the session quote.
     */
    protected function expectMaskedId(?string $maskedId): void
    {
        $mask = $this->getMockBuilder(QuoteIdMask::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load'])
            ->addMethods(['getMaskedId'])
            ->getMock();
        $mask->method('load')
            ->willReturnSelf();
        $mask->method('getMaskedId')
            ->willReturn($maskedId);

        $this->quoteIdMaskFactory->method('create')
            ->willReturn($mask);
    }

    public function testConfigCarriesSharedProviderKeysAndChallengeUrl(): void
    {
        $this->quote->method('getId')
            ->willReturn(11);
        $this->customer->method('getId')
            ->willReturn(42);

        $config = $this->block->getConfig();

        $this->assertTrue($config['payerAuthActive']);
        $this->assertSame(static::CHALLENGE_URL, $config['payerAuthChallengeUrl']);
    }

    public function testCustomerQuoteAddressesCartsMine(): void
    {
        $this->quote->method('getId')
            ->willReturn(11);
        $this->customer->method('getId')
            ->willReturn(42);

        $config = $this->block->getConfig();

        $this->assertSame(
            'https://store.example/rest/default/V1/carts/mine/paradoxlabs-cybersource/payer-auth/',
            $config['payerAuthEndpoint']
        );
    }

    public function testGuestQuoteAddressesGuestCartsByMaskedId(): void
    {
        $this->quote->method('getId')
            ->willReturn(11);
        $this->customer->method('getId')
            ->willReturn(null);
        $this->expectMaskedId('m4sk3d1d');

        $config = $this->block->getConfig();

        $this->assertSame(
            'https://store.example/rest/default/V1/guest-carts/m4sk3d1d/paradoxlabs-cybersource/payer-auth/',
            $config['payerAuthEndpoint']
        );
    }

    public function testGuestQuoteWithoutMaskYieldsNullEndpoint(): void
    {
        $this->quote->method('getId')
            ->willReturn(11);
        $this->customer->method('getId')
            ->willReturn(null);
        $this->expectMaskedId(null);

        $this->assertNull($this->block->getConfig()['payerAuthEndpoint']);
    }

    public function testMissingQuoteYieldsNullEndpoint(): void
    {
        $this->quote->method('getId')
            ->willReturn(null);

        $this->assertNull($this->block->getConfig()['payerAuthEndpoint']);
    }
}
