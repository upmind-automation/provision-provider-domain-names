<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Nominet\EppExtension;

use DOMElement;
use DOMNodeList;
use Illuminate\Support\Str;
use Metaregistrar\EPP\eppPollResponse as DefaultEppPollResponse;
use RuntimeException;
use Upmind\ProvisionProviders\DomainNames\Data\DomainNotification;

class eppPollResponse extends DefaultEppPollResponse
{
    /**
     * @var string|null|bool
     */
    protected $notificationType = false;

    /**
     * Determine the relevant normalised DomainNotification type, if any.
     *
     * @link https://registrars.nominet.uk/uk-namespace/registration-and-domain-management/registration-systems/epp/epp-notifications/
     *
     * @return string|null E.g. DomainNotification::TYPE_TRANSFER_IN or null if irrelevant/unknown.
     */
    public function getNotificationType(): ?string
    {
        if ($this->notificationType !== false) {
            return $this->notificationType;
        }

        $xpath = $this->xPath();

        /**
         * @link https://registrars.nominet.uk/uk-namespace/registration-and-domain-management/registration-systems/epp/epp-notifications/#registrar-change
         */
        $res = $xpath->query('/epp:epp/epp:response/epp:resData/std-notifications-1.2:rcData');
        if ($res instanceof DOMNodeList && $res->length > 0) {
            return $this->notificationType = DomainNotification::TYPE_TRANSFER_IN;
        }

        /**
         * @link https://registrars.nominet.uk/uk-namespace/registration-and-domain-management/registration-systems/epp/epp-notifications/#poor-quality-data
         */
        $res = $xpath->query('/epp:epp/epp:response/epp:resData/std-notifications-1.2:processData');
        if ($res instanceof DOMNodeList && $res->length > 0) {
            return $this->notificationType = DomainNotification::TYPE_DATA_QUALITY;
        }

        /**
         * @link https://registrars.nominet.uk/uk-namespace/registration-and-domain-management/registration-systems/epp/epp-notifications/#domain-cancelled
         */
        $res = $xpath->query('/epp:epp/epp:response/epp:resData/std-notifications-1.2:cancData');
        if ($res instanceof DOMNodeList && $res->length > 0) {
            return $this->notificationType = DomainNotification::TYPE_DELETED;
        }

        /**
         * @link https://registrars.nominet.uk/uk-namespace/registration-and-domain-management/registration-systems/epp/epp-notifications/#domains-released
         */
        $res = $xpath->query('/epp:epp/epp:response/epp:resData/std-notifications-1.2:relData');
        if ($res instanceof DOMNodeList && $res->length > 0) {
            if (Str::contains($this->getMessage(), ['Released'])) { //success
                return $this->notificationType = DomainNotification::TYPE_TRANSFER_OUT;
            }

            if (Str::contains($this->getMessage(), ['Rejected'])) { // failure
                return $this->notificationType = null;
            }
        }

        return $this->notificationType = null;
    }

    /**
     * Determine the domain names which are the subject of this notification message.
     *
     * @return string[]
     */
    public function getDomains(): array
    {
        $xpath = $this->xPath();

        /**
         * Attempt to find domain name(s) in the following query paths.
         */
        $queryPaths = [
            '//std-notifications-1.2:domainName',
            '//domain:name',
        ];

        foreach ($queryPaths as $path) {
            $nodeList = $xpath->query($path);
            if ($nodeList instanceof DOMNodeList && $nodeList->length > 0) {
                /** @var \Illuminate\Support\Collection $nodeListCollection */
                $nodeListCollection = collect($nodeList);

                return $nodeListCollection->map(function (DOMElement $element) {
                    return trim($element->textContent);
                })->all();
            }
        }

        throw new RuntimeException('Unable to determine domain name(s)');
    }
}
