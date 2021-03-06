<?php

namespace Omnifraud\Signifyd;

use Omnifraud\Contracts\ResponseInterface;
use Omnifraud\Contracts\ServiceInterface;
use Omnifraud\Request\Data\Address;
use Omnifraud\Request\Request;
use Omnifraud\Request\RequestException;
use Signifyd\Core\SignifydAPI;
use Signifyd\Core\SignifydSettings;
use Signifyd\Models as SignifydModels;

class SignifydService implements ServiceInterface
{
    protected $config = [
        'apiKey' => null,
        'logErrors' => false,
        'logWarnings' => false,
        'caseUrl' => 'https://app.signifyd.com/cases/%d',
    ];

    /** @var SignifydSettings */
    protected $settings;

    /** @var SignifydAPI */
    protected $apiClient;

    public function __construct(array $config)
    {
        $this->config = array_merge($this->config, $config);

        $this->settings = new SignifydSettings();
        foreach ($this->config as $key => $value) {
            $this->settings->$key = $value;
        }
    }

    public function trackingCode(string $pageType, string $sessionId, bool $quote = true): string
    {
        if ($quote) {
            $sessionId = json_encode($sessionId);
        }

        return <<<JS
(function (sid) {
    var script = document.createElement('script');
    script.setAttribute('src', 'https://cdn-scripts.signifyd.com/api/script-tag.js');
    script.setAttribute('data-order-session-id', sid);
    script.setAttribute('id', 'sig-api');

    document.body.appendChild(script);
})($sessionId);
JS;
    }

    /**
     * @param Request $request
     *
     * @return \Omnifraud\Signifyd\CaseIdResponse
     * @throws \Exception
     */
    public function validateRequest(Request $request): ResponseInterface
    {
        $case = $this->makeCase($request);

        $caseId = $this->getApiClient()->createCase($case);

        if (!$caseId) {
            throw new RequestException(
                'Could not create Signifyd Case: '
                . $this->getApiClient()->getLastErrorMessage()
            );
        }

        return new CaseIdResponse($caseId);
    }

    protected function getApiClient(): SignifydAPI
    {
        if (!$this->apiClient) {
            $this->apiClient = new SignifydAPI($this->settings);
        }
        return $this->apiClient;
    }

    public function setApiClient(SignifydAPI $apiClient): void
    {
        $this->apiClient = $apiClient;
    }

    protected function makeAddress(Address $address): SignifydModels\Address
    {
        $signifyAddress = new SignifydModels\Address();
        $signifyAddress->streetAddress = $address->getStreetAddress();
        $signifyAddress->unit = $address->getUnit();
        $signifyAddress->city = $address->getCity();
        $signifyAddress->provinceCode = $address->getState();
        $signifyAddress->postalCode = $address->getPostalCode();
        $signifyAddress->countryCode = $address->getCountryCode();

        return $signifyAddress;
    }

    protected function makeCase(Request $request): SignifydModels\CaseModel
    {
        $case = new SignifydModels\CaseModel();

        $purchase = new SignifydModels\Purchase();
        $purchase->browserIpAddress = $request->getSession()->getIp();
        $purchase->orderId = $request->getPurchase()->getId();
        $purchase->createdAt = $request->getPurchase()->getCreatedAt()->format('c');
        $purchase->paymentMethod = 'CREDIT_CARD';
        $purchase->currency = $request->getPurchase()->getCurrencyCode();
        $purchase->avsResponseCode = $request->getPayment()->getAvs();
        $purchase->cvvResponseCode = $request->getPayment()->getCvv();
        $purchase->orderChannel = 'WEB';
        $purchase->totalPrice = $request->getPurchase()->getTotal() / 100;
        $purchase->products = [];

        foreach ($request->getPurchase()->getProducts() as $product) {
            $p = new SignifydModels\Product();
            $p->itemId = $product->getSku();
            $p->itemName = $product->getName();
            $p->itemUrl = $product->getUrl();
            $p->itemImage = $product->getImage();
            $p->itemQuantity = $product->getQuantity();
            $p->itemPrice = $product->getPrice() / 100;
            $p->itemWeight = $product->getWeight();
            $p->itemIsDigital = $product->isDigital();
            $p->itemCategory = $product->getCategory();
            $p->itemSubCategory = $product->getSubCategory();
            $purchase->products[] = $p;
        }
        $case->purchase = $purchase;

        $card = new SignifydModels\Card();
        $card->cardHolderName = $request->getBillingAddress()->getFullName();
        $card->bin = $request->getPayment()->getBin();
        $card->last4 = $request->getPayment()->getLast4();
        $card->expiryMonth = $request->getPayment()->getExpiryMonth();
        $card->expiryYear = $request->getPayment()->getExpiryYear();
        $card->billingAddress = $this->makeAddress($request->getBillingAddress());
        $case->card = $card;

        $userAccount = new SignifydModels\UserAccount();
        $userAccount->emailAddress = $request->getAccount()->getEmail();
        $userAccount->username = $request->getAccount()->getUsername();
        $userAccount->phone = $request->getAccount()->getPhone();
        $userAccount->createdDate = $request->getAccount()->getCreatedAt()->format('c');
        $userAccount->accountNumber = $request->getAccount()->getId();
        $userAccount->lastOrderId = $request->getAccount()->getLastOrderId();
        $userAccount->aggregateOrderCount = $request->getAccount()->getTotalOrderCount();
        $userAccount->aggregateOrderDollars = $request->getAccount()->getTotalOrderAmount() / 100;
        $userAccount->lastUpdateDate = $request->getAccount()->getUpdatedAt()->format('c');
        $case->userAccount = $userAccount;

        $recipient = new SignifydModels\Recipient();
        $recipient->fullName = $request->getShippingAddress()->getFullName();
        $recipient->confirmationEmail = $request->getAccount()->getEmail();
        $recipient->confirmationPhone = $request->getShippingAddress()->getPhone();
        $recipient->deliveryAddress = $this->makeAddress($request->getShippingAddress());
        $case->recipient = $recipient;

        return $case;
    }

    /** @inheritdoc */
    public function updateRequest(Request $request): ResponseInterface
    {
        $case = $this->getApiClient()->getCase($request->getUid());

        if ($case === false) {
            throw new RequestException(
                'Could not retrieve Case ' . $request->getUid()
                . ': ' . $this->getApiClient()->getLastErrorMessage()
            );
        }

        return new CaseResponse($case);
    }

    public function getRequestExternalLink(string $requestUid): ?string
    {
        return sprintf($this->config['caseUrl'], $requestUid);
    }

    public function cancelRequest(Request $request): void
    {
        $result = $this->getApiClient()->cancelGuarantee($request->getUid());

        if ($result) {
            return;
        }
        if ($this->getApiClient()->getLastErrorMessage() === 'Guarantee not found') {
            return;
        }
        if (strpos($this->getApiClient()->getLastErrorMessage(), 'that is declined') !== false) {
            return;
        }
        throw new RequestException(
            'Error while Canceling Signifyd Guarantee: ' . $this->getApiClient()->getLastErrorMessage()
        );
    }

    public function logRefusedRequest(Request $request): void
    {
        // Nothing to do here
    }
}
