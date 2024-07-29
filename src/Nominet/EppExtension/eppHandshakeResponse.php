<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Nominet\EppExtension;

use Metaregistrar\EPP\eppResponse;

class eppHandshakeResponse extends eppResponse
{
    public function __construct()
    {
        parent::__construct();
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    #
    # DOMAIN HANDSHAKE RESPONSES
    #

    /**
     * @return string
     */
    public function getCaseId()
    {
        return $this->queryPath('/epp:epp/epp:response/epp:resData/h:hanData/h:caseId');
    }

    /**
     * @return string
     */
    public function getDomains()
    {
        return $this->queryPath('/epp:epp/epp:response/epp:resData/h:hanData/h:domainListData/*');
    }
}
