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

    public function getMessages()
    {
        if ($this->isAsync()) {
            return [];
        }

        $messages = [];

        $reviewDisposition = $this->case->reviewDisposition;
        if ($reviewDisposition !== self::UNSET_REVIEW) {
            $messages[] = new BaseMessage(
                $reviewDisposition === self::GOOD_REVIEW ? BaseMessage::TYPE_INFO : BaseMessage::TYPE_WARNING,
                'REV',
                'Review disposition: ' . $reviewDisposition
            );
        }

        return $messages;
    }

    public function getPercentScore()
    {
        return floor($this->case->score / 10);
    }

    public function isAsync()
    {
        if (!isset($this->case->guaranteeDisposition)) {
            return true;
        }
        return !in_array($this->case->guaranteeDisposition, self::CLOSED_GUARANTEES, true);
    }

    public function isGuaranteed()
    {
        if (!isset($this->case->guaranteeDisposition)) {
            return false;
        }

        return $this->case->guaranteeDisposition === self::APPROVED_GUARANTEE;
    }

    public function getRawResponse()
    {
        return json_encode($this->case);
    }

    public function getRequestUid()
    {
        return $this->case->caseId;
    }
}
