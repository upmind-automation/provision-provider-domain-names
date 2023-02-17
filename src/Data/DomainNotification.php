<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use DateTimeInterface;
use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * @property-read string|integer $id Notification message id
 * @property-read string $type Notification type
 * @property-read string $message Notification message
 * @property-read string[] $domains Array of subjected domain names
 * @property-read string $created_at Notification date-time
 * @property-read mixed[] $extra Any extra relevant data
 */
class DomainNotification extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'id' => ['required'],
            'type' => ['required', 'in:' . implode(',', self::VALID_TYPES)],
            'message' => ['required', 'string'],
            'domains' => ['required', 'array'],
            'domains.*' => ['required', 'domain_name'],
            'created_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'extra' => ['array'],
        ]);
    }

    /**
     * @var string[]
     */
    public const VALID_TYPES = [
        self::TYPE_DATA_QUALITY,
        self::TYPE_TRANSFER_IN,
        self::TYPE_TRANSFER_OUT,
        self::TYPE_RENEWED,
        self::TYPE_SUSPENDED,
        self::TYPE_DELETED,
    ];

    /**
     * Domain registrant data requires verification.
     *
     * @var string
     */
    public const TYPE_DATA_QUALITY = 'data_quality';

    /**
     * Domain successfully transferred in from another registrar.
     *
     * @var string
     */
    public const TYPE_TRANSFER_IN = 'transfer_in';

    /**
     * Domain successfully transferred out / released to another registrar.
     *
     * @var string
     */
    public const TYPE_TRANSFER_OUT = 'transfer_out';

    /**
     * Domain successfully renewed.
     *
     * @var string
     */
    public const TYPE_RENEWED = 'renewed';

    /**
     * Domain suspended e.g., after enough time having elapsed following an unresolved DQ issue.
     *
     * @var string
     */
    public const TYPE_SUSPENDED = 'suspended';

    /**
     * Domain deleted e.g., after enough time having elapsed following expiry.
     *
     * @var string
     */
    public const TYPE_DELETED = 'deleted';

    /**
     * @param int|string $messageId
     */
    public function setId($messageId): self
    {
        $this->setValue('id', $messageId);
        return $this;
    }

    public function setType(string $type): self
    {
        $this->setValue('type', $type);
        return $this;
    }

    public function setMessage(string $message): self
    {
        $this->setValue('message', $message);
        return $this;
    }

    /**
     * @param string[] $domains
     */
    public function setDomains(array $domains): self
    {
        $this->setValue('domains', $domains);
        return $this;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): self
    {
        $this->setValue('created_at', $createdAt->format('Y-m-d H:i:s'));
        return $this;
    }

    public function setExtra(?array $extra): self
    {
        $this->setValue('extra', $extra);
        return $this;
    }
}
