<?php

namespace Omnifraud\Signifyd;

use Omnifraud\Contracts\ResponseInterface;

class CaseIdResponse implements ResponseInterface
{
    protected $caseId;

    public function __construct($caseId)
    {
        $this->caseId = $caseId;
    }

    public function getMessages()
    {
        return [];
    }

    public function getPercentScore()
    {
        return null;
    }

    public function isAsync()
    {
        return true;
    }

    public function isGuaranteed()
    {
        return false;
    }

    public function getRequestUid()
    {
        return $this->caseId;
    }

    public function getRawResponse()
    {
        return json_encode(['caseId' => $this->caseId]);
    }
}
