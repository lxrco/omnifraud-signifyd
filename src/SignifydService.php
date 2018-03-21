<?php

namespace Omnifraud\Signifyd;

use Omnifraud\Contracts\ServiceInterface;
use Omnifraud\Request\Data\Address;
use Omnifraud\Request\Request;
use Omnifraud\Request\RequestException;
use Signifyd\Core\SignifydAPI;
use Signifyd\Core\SignifydSettings;
use Signifyd\Models;

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

    public function trackingCode($pageType)
    {
        return <<<JS
trackingCodes.push(function (sid) {
    var script = document.createElement('script');
    script.setAttribute('src', 'https://cdn-scripts.signifyd.com/api/script-tag.js');
    script.setAttribute('data-order-session-id', sid);
    script.setAttribute('id', 'sig-api');

    document.body.appendChild(script);
});
JS;
    }

    /**
     * @param Request $request
     *
     * @return CaseIdResponse
     * @throws \Exception
     */
    public function validateRequest(Request $request)
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

    public function getApiClient()
    {
        if (!$this->apiClient) {
            $this->apiClient = new SignifydAPI($this->settings);
        }
        return $this->apiClient;
    }

    public function setApiClient(SignifydAPI $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    protected function makeAddress(Address $address)
    {
        $signifyAddress = new Models\Address();
        $signifyAddress->streetAddress = $address->getStreetAddress();
        $signifyAddress->unit = $address->getUnit();
        $signifyAddress->city = $address->getCity();
        $signifyAddress->provinceCode = $address->getState();
        $signifyAddress->postalCode = $address->getPostalCode();
        $signifyAddress->countryCode = $address->getCountryCode();

        return $signifyAddress;
    }

    protected function makeCase(Request $request)
    {
        $case = new Models\CaseModel();

        $purchase = new Models\Purchase();
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
            $p = new Models\Product();
            $p->itemId = $product->getSku();
            $p->itemName = $product->getName();
            $p->itemUrl = $product->getUrl();
            $p->itemImage = $product->getImage();
            $p->itemQuantity = $product->getQuantity();
            $p->itemPrice = $product->getPrice() / 100;
            $purchase->products[] = $p;
        }
        $case->purchase = $purchase;

        $card = new Models\Card();
        $card->cardHolderName = $request->getBillingAddress()->getFullName();
        $card->bin = $request->getPayment()->getBin();
        $card->last4 = $request->getPayment()->getLast4();
        $card->expiryMonth = $request->getPayment()->getExpiryMonth();
        $card->expiryYear = $request->getPayment()->getExpiryYear();
        $card->billingAddress = $this->makeAddress($request->getBillingAddress());
        $case->card = $card;

        $userAccount = new Models\UserAccount();
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

        $recipient = new Models\Recipient();
        $recipient->fullName = $request->getShippingAddress()->getFullName();
        $recipient->confirmationEmail = $request->getAccount()->getEmail();
        $recipient->confirmationPhone = $request->getShippingAddress()->getPhone();
        $recipient->deliveryAddress = $this->makeAddress($request->getShippingAddress());
        $case->recipient = $recipient;

        return $case;
    }

    /** @inheritdoc */
    public function updateRequest(Request $request)
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

    public function getRequestExternalLink($requestUid)
    {
        return sprintf($this->config['caseUrl'], $requestUid);
    }

    public function cancelRequest($requestUid)
    {
        $result = $this->getApiClient()->cancelGuarantee($requestUid);

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

    public function logRefusedRequest(Request $request)
    {
        // Nothing to do here
    }
}
