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

    public function getScore(): ?float
    {
        return null;
    }

    public function isPending(): bool
    {
        return true;
    }

    public function isGuaranteed(): bool
    {
        return false;
    }

    public function getRequestUid(): string
    {
        return $this->caseId;
    }

    public function getRawResponse(): string
    {
        return json_encode(['caseId' => $this->caseId]);
    }
}
