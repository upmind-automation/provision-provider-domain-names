<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Nominet\EppExtension;

use Metaregistrar\EPP\eppResponse;

class eppReleaseResponse extends eppResponse
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
    # DOMAIN RELEASE RESPONSES
    #

    /**
     * @return null|string
     */
    public function getDetails()
    {
        return $this->queryPath('/epp:epp/epp:response/epp:resData/r:releasePending');
    }

    // /**
    //  * @return null|string
    //  */
    // public function getMsg() {
    //     return $this->queryPath('/epp:epp/epp:response/response:result/result:msg');
    // }

    // /**
    //  * @return null|string
    //  */
    // public function getDetails() {
    //     return $this->queryPath('/epp:epp/epp:response/response:resData/resData:r:releasePending');
    // }
}
