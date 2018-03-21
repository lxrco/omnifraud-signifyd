<?php

namespace Tests\Unit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Omnifraud\Contracts\MessageInterface;
use Omnifraud\Contracts\ResponseInterface;
use Omnifraud\Contracts\ServiceInterface;
use Omnifraud\Request\Request;
use Omnifraud\Request\RequestException;
use Omnifraud\Signifyd\CaseResponse;
use Omnifraud\Signifyd\SignifydService;
use Omnifraud\Testing\MakesTestRequests;
use PHPUnit\Framework\TestCase;
use Signifyd\Core\SignifydAPI;
use Signifyd\Models\CaseModel;

class SignifydServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use MakesTestRequests;

    public function testTrackingCodeMethod()
    {
        $driver = new SignifydService([
            'apiKey' => 'test',
        ]);

        $this->assertContains(
            "setAttribute('data-order-session-id', sid)",
            $driver->trackingCode(ServiceInterface::PAGE_CHECKOUT) . "\n"
        );

        $this->assertContains(
            "setAttribute('data-order-session-id', sid)",
            $driver->trackingCode(ServiceInterface::PAGE_ALL) . "\n"
        );
    }

    public function testValidateRequestWithCompleteRequest()
    {
        // Set up
        /** @var CaseModel $madeCase */
        $madeCase = null;
        $mockApiClient = Mockery::mock(SignifydAPI::class);
        $mockApiClient->shouldReceive('createCase')
            ->once()
            ->with(Mockery::on(function ($arg) use (&$madeCase) {
                $madeCase = $arg;

                return $arg instanceof CaseModel;
            }))
            ->andReturn('9876');

        $service = new SignifydService([]);
        $service->setApiClient($mockApiClient);

        // Run
        $response = $service->validateRequest($this->makeTestRequest());

        // Asserts
        $this->assertTrue($response->isAsync());
        $this->assertEmpty($response->getMessages());
        $this->assertNull($response->getPercentScore());
        $this->assertSame('9876', $response->getRequestUid());
        $this->assertFalse($response->isGuaranteed());

        $this->assertEquals([
            'purchase' => [
                'orderSessionId' => null,
                'browserIpAddress' => '1.2.3.4',
                'orderId' => 1,
                'createdAt' => '2017-09-02T12:12:12+00:00',
                'paymentGateway' => null,
                'paymentMethod' => 'CREDIT_CARD',
                'currency' => 'CAD',
                'avsResponseCode' => 'Y',
                'cvvResponseCode' => 'M',
                'transactionId' => null,
                'orderChannel' => 'WEB',
                'receivedBy' => null,
                'totalPrice' => 560.25,
                'products' => [
                    [
                        'itemId' => 'SKU1',
                        'itemName' => 'Product number 1',
                        'itemUrl' => 'http://www.example.com/product-1',
                        'itemImage' => 'http://www.example.com/product-1/cover.jpg',
                        'itemQuantity' => 1,
                        'itemPrice' => 60.25,
                        'itemWeight' => null,
                        'itemIsDigital' => null,
                        'itemCategory' => null,
                        'itemSubCategory' => null,
                    ],
                    [
                        'itemId' => 'SKU2',
                        'itemName' => 'Product number 2',
                        'itemUrl' => 'http://www.example.com/product-2',
                        'itemImage' => 'http://www.example.com/product-2/cover.jpg',
                        'itemQuantity' => 2,
                        'itemPrice' => 250.00,
                        'itemWeight' => null,
                        'itemIsDigital' => null,
                        'itemCategory' => null,
                        'itemSubCategory' => null,
                    ],
                ],
                'shipments' => null,
                'discountCodes' => null,
            ],
            'recipient' => [
                'fullName' => 'John Shipping',
                'confirmationEmail' => 'test@example.com',
                'confirmationPhone' => '1234567891',
                'organization' => null,
                'deliveryAddress' => [
                    'streetAddress' => '1 shipping street',
                    'unit' => '25',
                    'city' => 'Shipping Town',
                    'provinceCode' => 'Shipping State',
                    'postalCode' => '12345',
                    'countryCode' => 'US',
                    'latitude' => null,
                    'longitude' => null,
                ],
            ],
            'card' => [
                'cardHolderName' => 'John Billing',
                'bin' => 457173,
                'last4' => '9000',
                'expiryMonth' => 9,
                'expiryYear' => 20,
                'hash' => null,
                'billingAddress' => [
                    'streetAddress' => '1 billing street',
                    'unit' => '1A',
                    'city' => 'Billing Town',
                    'provinceCode' => 'Billing State',
                    'postalCode' => '54321',
                    'countryCode' => 'CA',
                    'latitude' => null,
                    'longitude' => null,
                ],
            ],
            'userAccount' => [
                'emailAddress' => 'test@example.com',
                'username' => 'username',
                'phone' => '1234567890',
                'createdDate' => '2017-01-01T01:01:01+00:00',
                'accountNumber' => 'ACCOUNT_ID',
                'lastOrderId' => 'LAST_ORDER_ID',
                'aggregateOrderCount' => 5,
                'aggregateOrderDollars' => 1287.00,
                'lastUpdateDate' => '2017-05-12T02:02:02+00:00',
            ],
            'seller' => null,
        ], json_decode($madeCase->toJson(), true));
    }

    public function testValidateRequestWithFailingRequest()
    {
        // Set up
        /** @var CaseModel $madeCase */
        $madeCase = null;
        $mockApiClient = Mockery::mock(SignifydAPI::class);
        $mockApiClient->shouldReceive('createCase')
            ->once()
            ->with(Mockery::on(function ($arg) use (&$madeCase) {
                $madeCase = $arg;

                return $arg instanceof CaseModel;
            }))
            ->andReturn(false);
        $mockApiClient->shouldReceive('getLastErrorMessage')
            ->andReturn('ERROR');

        $service = new SignifydService([]);
        $service->setApiClient($mockApiClient);

        $this->expectException(RequestException::class);

        // Run
        $service->validateRequest($this->makeTestRequest());
    }

    public function openGuaranteeDispositionProvider()
    {
        return [
            ['PENDING'],
            ['IN_REVIEW'],
        ];
    }

    protected function getSignifydCaseResponse($merge = [])
    {
        // Example response from: https://www.signifyd.com/docs/api/#/reference/cases/get-a-case
        return (object)array_merge([
            'guaranteeDisposition' => 'DECLINED',
            'investigationId' => 44,
            'caseId' => 44,
            'headline' => 'Maxine Trycia',
            'uuid' => '97c56c86-7984-44fa-9a3e-7d5f34d1bead',
            'updatedAt' => '2017-06-09T21:03:26+0000',
            'status' => 'DISMISSED',
            'reviewDisposition' => 'GOOD',
            'orderId' => '0000219221',
            'orderAmount' => 74.99,
            'orderOutcome' => 'SUCCESSFUL',
            'currency' => 'USD',
            'score' => 776,
            'orderDate' => '2016-10-28T22:54:31+0000',
            'createdAt' => '2016-10-31T23:46:37+0000',
            'testInvestigation' => false,
            'guaranteeEligible' => false,
        ], $merge);
    }

    /** @dataProvider openGuaranteeDispositionProvider */
    public function testUpdateRequestWithUncompleteRequest($guarantee)
    {
        // Set up
        $responseObject = $this->getSignifydCaseResponse([
            'guaranteeDisposition' => $guarantee,
        ]);

        $mockApiClient = Mockery::mock(SignifydAPI::class);
        $mockApiClient->shouldReceive('getCase')
            ->once()
            ->with(44)
            ->andReturn($responseObject);

        $service = new SignifydService([]);
        $service->setApiClient($mockApiClient);

        $request = new Request();
        $request->setUid(44);

        $response = $service->updateRequest($request);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertTrue($response->isAsync());
    }

    public function testUpdateRequestReturnsCaseResponseWithScoreAndMessages()
    {
        // Set up
        $responseObject = $this->getSignifydCaseResponse();
        $responseObject->reviewDisposition = CaseResponse::GOOD_REVIEW;

        $mockApiClient = Mockery::mock(SignifydAPI::class);
        $mockApiClient->shouldReceive('getCase')
            ->once()
            ->with(44)
            ->andReturn($responseObject);

        $service = new SignifydService([]);
        $service->setApiClient($mockApiClient);

        $request = new Request();
        $request->setUid(44);

        $response = $service->updateRequest($request);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertFalse($response->isAsync());
        $this->assertEquals(44, $response->getRequestUid());
        $this->assertEquals(77.6, $response->getPercentScore());
        $this->assertFalse($response->isGuaranteed());
        $this->assertEquals(json_encode($responseObject), $response->getRawResponse());
        $this->assertCount(1, $response->getMessages());
        $this->assertEquals('REV', $response->getMessages()[0]->getCode());
    }

    public function testUpdateRequestWithGuaranteedResponse()
    {
        // Set up with actual response
        $responseObject = $this->getSignifydCaseResponse([
            'associatedTeam' => [
                'teamId' => 1000000,
                'teamName' => 'LXRanCo Test',
            ],
            'guaranteeDisposition' => 'APPROVED',
            'investigationId' => 469889701,
            'caseId' => 123456789,
            'headline' => 'Hugo Vacher',
            'uuid' => '00000000-0000-0000-0000-000000000000',
            'updatedAt' => '2017-11-21T18:52:21+0000',
            'status' => 'OPEN',
            'reviewDisposition' => 'UNSET',
            'orderId' => '11738',
            'orderAmount' => 126.48,
            'orderOutcome' => 'SUCCESSFUL',
            'currency' => 'CAD',
            'adjustedScore' => 712.13990730750788,
            'score' => 712.13990730750788,
            'orderDate' => '2017-11-17T21:39:18+0000',
            'createdAt' => '2017-11-17T21:39:37+0000',
            'testInvestigation' => true,
            'guaranteeEligible' => false,
        ]);

        $mockApiClient = Mockery::mock(SignifydAPI::class);
        $mockApiClient->shouldReceive('getCase')
            ->once()
            ->with(44)
            ->andReturn($responseObject);

        $service = new SignifydService([]);
        $service->setApiClient($mockApiClient);

        $request = new Request();
        $request->setUid(44);

        $response = $service->updateRequest($request);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertTrue($response->isGuaranteed());
    }

    public function testUpdateRequestWithInvalidRequestUid()
    {
        // Set up
        $mockApiClient = Mockery::mock(SignifydAPI::class);
        $mockApiClient->shouldReceive('getCase')
            ->once()
            ->andReturn(false);
        $mockApiClient->shouldReceive('getLastErrorMessage')
            ->andReturn('ERROR');

        $service = new SignifydService([]);
        $service->setApiClient($mockApiClient);


        $this->expectException(RequestException::class);

        $request = new Request();
        $request->setUid('invalid');

        $service->updateRequest($request);
    }

    public function messagesDataProvider()
    {
        return [
            [
                ['guaranteeDisposition' => 'APPROVED', 'reviewDisposition' => 'GOOD'],
                [
                    ['type' => MessageInterface::TYPE_INFO, 'message' => 'Review disposition: GOOD'],
                ],
            ],
            [
                ['guaranteeDisposition' => 'DECLINED', 'reviewDisposition' => 'FRAUDULENT'],
                [
                    ['type' => MessageInterface::TYPE_WARNING, 'message' => 'Review disposition: FRAUDULENT'],
                ],
            ],
            [
                ['guaranteeDisposition' => 'CANCELED', 'reviewDisposition' => 'UNSET'],
                [],
            ],
        ];
    }

    /** @dataProvider messagesDataProvider */
    public function testUpdateRequestMessages($merge, $expectedMessages)
    {
        // Set up
        $responseObject = $this->getSignifydCaseResponse($merge);

        $mockApiClient = Mockery::mock(SignifydAPI::class);
        $mockApiClient->shouldReceive('getCase')
            ->once()
            ->with(44)
            ->andReturn($responseObject);

        $service = new SignifydService([]);
        $service->setApiClient($mockApiClient);

        $request = new Request();
        $request->setUid(44);

        $response = $service->updateRequest($request);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertFalse($response->isAsync());
        $this->assertEquals(json_encode($expectedMessages), json_encode($response->getMessages()));
    }

    public function testGetRequestExternalLink()
    {
        $service = new SignifydService([]);

        $this->assertEquals('https://app.signifyd.com/cases/44', $service->getRequestExternalLink(44));
    }

    public function testCancelRequest()
    {
        // Set up
        /** @var CaseModel $madeCase */
        $madeCase = null;
        $mockApiClient = Mockery::mock(SignifydAPI::class);
        $mockApiClient->shouldReceive('cancelGuarantee')
            ->once()
            ->with('994312')
            ->andReturn('CANCELED');

        $service = new SignifydService([]);
        $service->setApiClient($mockApiClient);

        // Run
        $service->cancelRequest('994312');
    }

    public function testCancelRequestWithFailingRequest()
    {
        // Set up
        /** @var CaseModel $madeCase */
        $madeCase = null;
        $mockApiClient = Mockery::mock(SignifydAPI::class);
        $mockApiClient->shouldReceive('cancelGuarantee')
            ->once()
            ->with('994312')
            ->andReturn(false);
        $mockApiClient->shouldReceive('getLastErrorMessage')
            ->andReturn('Network Error: Could not find signifyd.com');

        $service = new SignifydService([]);
        $service->setApiClient($mockApiClient);

        $this->expectException(RequestException::class);

        // Run
        $service->cancelRequest('994312');
    }

    public function testCancelRequestWithNonDeclinedRequest()
    {
        // Set up
        /** @var CaseModel $madeCase */
        $madeCase = null;
        $mockApiClient = Mockery::mock(SignifydAPI::class);
        $mockApiClient->shouldReceive('cancelGuarantee')
            ->once()
            ->with('994312')
            ->andReturn(false);
        $mockApiClient->shouldReceive('getLastErrorMessage')
            ->andReturn('Cannot cancel a guarantee that is declined');

        $service = new SignifydService([]);
        $service->setApiClient($mockApiClient);

        // Run (This should not throw
        $service->cancelRequest('994312');
    }

    public function testCancelRequestWithNonSubmitedRequest()
    {
        // Set up
        /** @var CaseModel $madeCase */
        $madeCase = null;
        $mockApiClient = Mockery::mock(SignifydAPI::class);
        $mockApiClient->shouldReceive('cancelGuarantee')
            ->once()
            ->with('994312')
            ->andReturn(false);
        $mockApiClient->shouldReceive('getLastErrorMessage')
            ->andReturn('Guarantee not found');

        $service = new SignifydService([]);
        $service->setApiClient($mockApiClient);

        // Run (This should not throw
        $service->cancelRequest('994312');
    }

    public function testRequestNotSentForReview()
    {
        $responseObject = json_decode('{
    "associatedTeam": {
        "teamId": 123456,
        "teamName": "LXRandCo"
    },
    "investigationId": 456789,
    "caseId": 987654,
    "headline": "Hugo Vacher",
    "uuid": "a1a1a1a1-a2a2-3e33-a123-4a4b4c4d4e4f",
    "updatedAt": "2017-10-30T18:25:56+0000",
    "status": "OPEN",
    "reviewDisposition": "UNSET",
    "orderId": "88888",
    "orderAmount": 126.48,
    "orderOutcome": "SUCCESSFUL",
    "currency": "CAD",
    "adjustedScore": 504.11790076028683,
    "score": 504.11790076028683,
    "orderDate": "2017-10-30T18:25:31+0000",
    "createdAt": "2017-10-30T18:25:56+0000",
    "testInvestigation": false,
    "guaranteeEligible": true
}');

        $mockApiClient = Mockery::mock(SignifydAPI::class);
        $mockApiClient->shouldReceive('getCase')
            ->once()
            ->with(44)
            ->andReturn($responseObject);

        $service = new SignifydService([]);
        $service->setApiClient($mockApiClient);

        $request = new Request();
        $request->setUid(44);

        $response = $service->updateRequest($request);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertTrue($response->isAsync());
        $this->assertFalse($response->isGuaranteed());
        $this->assertEquals(50.4, $response->getPercentScore());
    }
}
