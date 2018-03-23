# Omnifraud: Signifyd

**Signifyd driver for the Omnifraud PHP fraud prevention library**

[![Build Status](https://travis-ci.org/lxrco/omnifraud-signifyd.svg?branch=master)](https://travis-ci.org/lxrco/omnifraud-signifyd)
[![Test Coverage](https://api.codeclimate.com/v1/badges/d6fc017f691c3d77ffb6/test_coverage)](https://codeclimate.com/github/lxrco/omnifraud-signifyd/test_coverage)

[Omnifraud](https://github.com/lxrco/omnifraud) is an fraud prevention livrary for PHP. It aims at providing a clear and consisten API for interacting with different fraud prevention service.

### Installation

```bash
composer require omnifraud/signifyd
```

### Usage

The Signifyd fraud service driver implements the following methods:
`trackingCode` ,`validateRequest`, `updateRequest`, `getRequestExternalLink`, `cancelRequest`.

The only method that is left empty is `logRefusedRequest` as it is not a needed for Signifyd.

#### Initialisation

The SignifydService constructor accepts the following configuration values (these are the default values):
```php
$service = new KountService([
    'apiKey' => null, // Signifyd API key
    'caseUrl' => 'https://app.signifyd.com/cases/%d', // Url where cases are visible
    //... 
]);
```

NOTE: Anything supported by the official [SignifydSettings](https://github.com/signifyd/signifyd-php/blob/master/lib/Core/SignifydSettings.php) class can be passed a config

#### Submitting a sale

You can use the `validateRequest` to submit a request, method to get an async response that will need to be updated later.

Signifyd recommends sending as much fields as possible, take a look at [this example](https://github.com/lxrco/omnifraud-common/blob/master/src/Testing/MakesTestRequests.php) to learn about all the fields.

```php
<?php
$request = new \Omnifraud\Request\Request();

// Set request informations
$request->getPayment()->setBin('1234');
// Etc... Anything provided in the request is sent to Signifyd, except the billing address phone number


// Send the request

$service = new \Omnifraud\Signifyd\SignifydService($serviceConfig);

$response = $service->validateRequest($request);

// Should always be true for a first request
if ($response->isAsync()) {
    // Queue job for later update
}

```

#### Refreshing a case

Signifyd always answers with an Async response, so you will need to refresh the request in order to get the answer, this
is best done by queueing a job.

You can also use this method to get the request result later on (for example if you sent it for manual evaluation).

```php
$service = new \Omnifraud\Signifyd\SignifydService($serviceConfig);

$request = new \Omnifraud\Request\Request();
$request->setUid($requestUid);

$response = $service->updateRequest($request);

// Use for updating
$requestUid = $response->getRequestUid();

if ($response->isAsync()) {
    // Retry later
    return;
}

$score = $response->getPercentScore(); // Signifyd score divided by 10, 100 is best, 0 is worst
$guaranteed = $response->isGuaranteed(); // If covered by Signifyd guarantee

```

NOTE: The response from updateRequest can still be async, if this is the case, it means you have to retry later.


#### Cancelling a Guarantee

If you are refunding or cancelling an order, it is a good idea to cancel the guarantee as Signifyd will refund the fee.

```php
$service = new \Omnifraud\Signifyd\SignifydService($serviceConfig);

$request = new \Omnifraud\Request\Request();
$request->setUid($requestUid);

try {
    $service->cancelRequest($request);
} catch(\Omnifraud\Request\RequestException $e) {
    // Something went wrong
}
```

#### Session id (or [Device Fingerprint](https://www.signifyd.com/docs/api/#/reference/device-fingerprint))

You [implement the frontend code](https://github.com/lxrco/omnifraud#frontend-code) in order to track devices pre-purchase.

Then you will need to add the sessionId to the request:

```php
// Retrieve the session ID with some method, it can come from server side cookies/session also
$request->setSession($_POST['sessionId']);
```


#### Linking to a case

In order to get the link to view a case on Signifyd, you just need the UID:

```php
$service = new \Omnifraud\Kount\KountService($serviceConfig);
$url = $service->getRequestExternalLink($requestUid);
```
