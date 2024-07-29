<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Requests;

/**
 *         ,_     _
 *        |\\_,-~/
 *       / _  _ |    ,--.
 *      (  @  @ )   / ,-'
 *      \  _T_/-._( (
 *      /         `. \
 *      |         _  \ |
 *      \ \ ,  /      |
 *      || |-_\__   /
 *      ((_/`(____,-'
 *
 * Class eppTransferRequest
 * @package Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Requests
 */
class EppTransferRequest extends \Metaregistrar\EPP\eppTransferRequest
{
    /**
     * Add the hexonet ACTION=USERTRANSFER extension (for internal domain transfers).
     *
     * @link https://wiki.hexonet.net/wiki/EPP:TransferDomain
     *
     * @throws \DOMException
     */
    public function addUserTransferAction(): void
    {
        // Set the extension tag for internal transfer
        $extension = $this->createElement('extension');
        $keyValueParent = $this->createElement('keyvalue:extension');

        $keyValueChild = $this->createElement('keyvalue:kv');
        $keyValueChild->setAttribute('key', 'ACTION');
        $keyValueChild->setAttribute('value', 'USERTRANSFER');

        $keyValueParent->appendChild($keyValueChild);
        $extension->appendChild($keyValueParent);

        // Hexonet throws a fit if the extension comes after the clTRID tag. So let's put it immediately before it.
        $commandElement = $this->getCommand();
        $commandElement->insertBefore($extension, $this->getElementsByTagName('clTRID')->item(0));
    }
}
