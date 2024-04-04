# EuroDNS Upmind Module
This module can register, transfer, renew, and manage EuroDNS domains in the **Upmind** platform.

# Installation 

1) This module needs to be placed on the folder path upmind/provision-provider-domain-names/src as folder name EuroDNS
2) upmind/provision-provider-domain-names/src/LaravelServiceProvider.php  on this file we need to add,
   # On the   import class section add 
     use Upmind\ProvisionProviders\DomainNames\EuroDNS\Provider as EuroDNS;
   # In the bottom of the boot function add
     $this->bindProvider('domain-names', 'eurodns', EuroDNS::class);



# Module supports
  1) poll()
  2) domainAvailabilityCheck()
  3) register()
  4) transfer()
  5) renew()
  6) getInfo()
  7) updateRegistrantContact()
  8) updateNameservers()
  9) setLock()
  10) setAutoRenew()
  11) getEppCode()
  12) updateIpsTag()	- not supported by EuroDNS

# Api functions
   # Poll messages
     In euroDNS, an API call Retrieves only one message at a time to get the next message 
     we need to Acknowledge the poll message by using the Acknowledge poll message API calls
     But, on the module, we can fetch the poll messages more than one at a time.
     **API reference**: https://agent.api-eurodns.com/doc/poll/retrieve 
                    https://agent.api-eurodns.com/doc/poll/acknowledge
   # Domain Availability Check
     We can check domain availability using EuroDNS.It will return whether the domain is available or not.
     If not it will also return the reason for non-availability
     **API reference**: https://agent.api-eurodns.com/doc/domain/check
   # Register
     We can register new domains in EuroDNS.If we try to register an already existing domain or any other error 
     occurs it will return the error message otherwise we will get a successful response like this
          <?xml version="1.0" encoding="UTF-8"?>
            <response xmlns="http://www.eurodns.com/">
                <result code="1001">
                    <msg>Command completed successfully, action pending</msg>
                </result>
                <resData>
                    <domain:create>
                        <domain:name>#DOMAIN NAME#</domain:name>
                        <domain:roid>#DOMAIN ROID#</domain:roid>
                    </domain:create>
                </resData>
            </response>
      If we get this response we will fetch the domain info by calling the domain info API call. However,
      we delayed calling the API by 3 seconds because sometimes  takes time to fetch the newly added domain.
      **API reference**:https://agent.api-eurodns.com/doc/domain/create
   # Transfer
     We can transfer domains from other domains into EuroDNS.If it is possible we will get a response like 
        <?xml version="1.0" encoding="UTF-8"?>
       <response xmlns="http://www.eurodns.com/">
           <result code="1001">
               <msg>Command completed successfully, action pending</msg>
           </result>
       </response>
       **API reference**:https://agent.api-eurodns.com/doc/domain/transfer/generic
   # Renew
     We can renew a domain by giving the domain and renewing the year to the renew API. It will return 
     successful responses like 
        <?xml version="1.0" encoding="UTF-8"?>
       <response xmlns="http://www.eurodns.com/">
           <result code="1001">
               <msg>Command completed successfully, action pending</msg>
           </result>
       </response>
      **API reference**:https://agent.api-eurodns.com/doc/domain/renew
   # GetInfo
      We can get the domain info by passing the domain name to obtain all the information 
      for a domain name in your account. It will return all the info in response if the domain is 
      registered in the account otherwise error message will be shown.
      **API reference**:https://agent.api-eurodns.com/doc/domain/info

   # Update Registrant Contact
      We can update the registrant's contact by using the Update API call. It will return a response like
      this on successful updation,
      <?xml version="1.0" encoding="UTF-8"?>
       <response xmlns="http://www.eurodns.com/">
           <result code="1001">
               <msg>Command completed successfully, action pending</msg>
           </result>
       </response>
       **API reference**:https://agent.api-eurodns.com/doc/domain/update/generic

   # Update Nameservers
     We can update the update nameservers by using the Update API call. It will return a response like
     this on successful updation,

      <?xml version="1.0" encoding="UTF-8"?>
       <response xmlns="http://www.eurodns.com/">
           <result code="1001">
               <msg>Command completed successfully, action pending</msg>
           </result>
       </response>
     **API reference**:https://agent.api-eurodns.com/doc/domain/update/generic

   # Set Lock
     We can set lock and unset the lock using Lock and Unlock API calls. By default when registering a 
     domain it is in lock state.On successful updation both will return response like ,
       <?xml version="1.0" encoding="UTF-8"?>
       <response xmlns="http://www.eurodns.com/">
           <result code="1001">
               <msg>Command completed successfully, action pending</msg>
           </result>
       </response>
      **API reference**:https://agent.api-eurodns.com/doc/domain/lock
                        https://agent.api-eurodns.com/doc/domain/unlock
   # Get EppCode
     To get Epp code first we need to call the transfer out API this will create a eppCode in 
     the domain info response.To generate EppCode it will take around 15-30 min.
     After that when we call the domain info of the domain we will get the eppCode

     **API reference**:https://agent.api-eurodns.com/doc/domain/transferout 
                     https://agent.api-eurodns.com/doc/domain/info

     
     
     
     
     
      


    

      

        
                     
     

     
     





