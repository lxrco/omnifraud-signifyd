<?php

namespace Omnifraud\Signifyd;

use Omnifraud\Contracts\ResponseInterface;
use Omnifraud\Response\BaseMessage;

class CaseResponse implements ResponseInterface
{
    const CLOSED_GUARANTEES = [
        'APPROVED',
        'DECLINED',
        'CANCELED',
    ];

    const APPROVED_GUARANTEE = 'APPROVED';

    const UNSET_REVIEW = 'UNSET';

    const GOOD_REVIEW = 'GOOD';

    /** @var \stdClass */
    protected $case;

    public function __construct($case)
    {
        $this->case = $case;
    }

    public function getScore(): float
    {
        return floor($this->case->score) / 10;
    }

    public function isPending(): bool
    {
        if (!isset($this->case->guaranteeDisposition)) {
            return true;
        }
        return !in_array($this->case->guaranteeDisposition, self::CLOSED_GUARANTEES, true);
    }

    public function isGuaranteed(): bool
    {
        if (!isset($this->case->guaranteeDisposition)) {
            return false;
        }

        return $this->case->guaranteeDisposition === self::APPROVED_GUARANTEE;
    }

    public function getRequestUid(): string
    {
        return $this->case->caseId;
    }

    public function getRawResponse(): string
    {
        return json_encode($this->case);
    }
}
