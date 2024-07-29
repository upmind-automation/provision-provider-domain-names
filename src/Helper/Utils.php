<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Helper;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use libphonenumber\PhoneNumberUtil;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Helper\Tlds\WhoisPrivacy;

class Utils
{
    /**
     * Formats a date
     *
     * @param string|null $date
     * @param string|null $format
     * @param string|null $adjustHours
     *
     * @return string|null Formatted date, or null
     */
    public static function formatDate(?string $date, ?string $format = null, ?string $adjustHours = null): ?string
    {
        if (empty($date)) {
            return null;
        }

        $dateObject = Carbon::parse($date);

        if ($adjustHours) {
            $dateObject->addHours($adjustHours);
        }

        if (!is_null($format)) {
            return $dateObject->format($format);
        }

        return $dateObject->toDateTimeString();
    }

    /**
     * Returns SLD and TLD from a domain, represented as a string.
     *
     * @param string $domain
     * @return array
     */
    public static function getSldTld(string $domain): array
    {
        $parts = explode('.', $domain, 2);

        return [
            'sld' => array_shift($parts),
            'tld' => implode('.', $parts),
        ];
    }

    /**
     * Get the tld of the given domain name.
     */
    public static function getTld(string $domain): string
    {
        return explode('.', $domain, 2)[1];
    }

    /**
     * Get a fully formed domain name from its constituent raw second- and top-level parts.
     *
     * @param string $sld Second-level domain e.g., upmind
     * @param string $tld Top-level domain e.g., .com
     *
     * @return string Domain name e.g., upmind.com
     */
    public static function getDomain(string $sld, string $tld): string
    {
        return implode('.', [self::normalizeSld($sld), self::normalizeTld($tld)]);
    }

    /**
     * Normalize a second-level domain by stripping extra periods (.).
     */
    public static function normalizeSld(string $sld): string
    {
        return trim(strtolower($sld), '.');
    }

    /**
     * Normalize a top-level domain by trimming periods (.) and shifting
     * to lowercase.
     *
     * @param string $tld E.g., '.ES'
     *
     * @return string E.g., 'es'
     */
    public static function normalizeTld(string $tld): string
    {
        return trim(strtolower($tld), '.');
    }

    /**
     * Returns the normalized root top-level domain for the given tld, for example
     * given ".co.uk" returns "uk".
     */
    public static function getRootTld(string $tld): string
    {
        $parts = explode('.', self::normalizeTld($tld));

        return array_pop($parts);
    }

    /**
     * Use system DNS resolver to look up a domain's NS records.
     *
     * @param string $domain
     * @param bool $orFail When lookup fails: if true throw an error, otherwise return null
     *
     * @return string[]|null Array of nameserver hostnames
     *
     * @throws ProvisionFunctionError
     */
    public static function lookupNameservers(string $domain, bool $orFail = true): ?array
    {
        try {
            return array_column(dns_get_record($domain, DNS_NS), 'target');
        } catch (Throwable $e) {
            if ($orFail) {
                throw new ProvisionFunctionError(sprintf('Nameserver lookup for %s failed', $domain), 0, $e);
            }

            return null;
        }
    }

    /**
     * Use system DNS resolver to look up a host's IP address.
     *
     * @param string $domain
     * @param bool $orFail When lookup fails: if true throw an error, otherwise return null
     *
     * @return string|null IP address
     *
     * @throws ProvisionFunctionError
     */
    public static function lookupIpAddress(string $domain, bool $orFail = true): ?string
    {
        try {
            return Arr::first(
                array_column(dns_get_record($domain, DNS_A), 'ip')
                    ?: array_column(dns_get_record($domain, DNS_AAAA), 'ipv6')
            );
        } catch (Throwable $e) {
            if ($orFail) {
                throw new ProvisionFunctionError(sprintf('IP lookup for %s failed', $domain), 0, $e);
            }

            return null;
        }
    }

    /**
     * Determine whether  the registry of the given TLD supports domain locking.
     */
    public static function tldSupportsLocking(string $tld): bool
    {
        $unsupported = [
            'io',
            'de',
        ];

        return !in_array(static::getRootTld($tld), $unsupported);
    }

    /**
     * Determine whether the registry of the given TLD supports explicit renewal.
     */
    public static function tldSupportsExplicitRenewal(string $tld): bool
    {
        $unsupported = [
            'abogado',
            'at',
            'be',
            'ch',
            'de',
            'fr',
            'gs',
            'it',
            'jobs',
            'li',
            'ltd',
            'nl',
            'pl',
            'pw',
            'tk',
        ];

        return !in_array(static::getRootTld($tld), $unsupported);
    }

    /**
     * Determine whether the registry of the given TLD supports WHOIS privacy.
     */
    public static function tldSupportsWhoisPrivacy(string $tld): bool
    {
        return WhoisPrivacy::tldIsSupported($tld);
    }

    /**
     * Determine whether the registry of the given TLD supports contacts when initiating transfer.
     */
    public static function tldSupportsTransferContacts(string $tld): bool
    {
        $unsupported = [
            'online',
        ];

        return !in_array(static::getRootTld($tld), $unsupported);
    }

    /**
     * Convert a phone from "international format" (beginning with `+` and intl
     * dialling code) to "EPP format" described in RFC5733. To validate a phone
     * number is in valid international format, you can use the provided
     * `international_phone` rule.
     *
     * @link https://tools.ietf.org/html/rfc5733#section-2.5
     *
     * @param string|null $number Phone number in "international format" E.g., +447515878251
     *
     * @return string|null Phone number in "EPP format" E.g., +44.7515878251
     *
     * @throws \libphonenumber\NumberParseException If not a valid international phone number
     */
    public static function internationalPhoneToEpp(?string $number): ?string
    {
        if (empty($number)) {
            return null;
        }

        $phone = PhoneNumberUtil::getInstance()->parse($number, null);
        $diallingCode = $phone->getCountryCode();
        $nationalNumber = $phone->getNationalNumber();

        return sprintf('+%s.%s', $diallingCode, $nationalNumber);
    }

    /**
     * Convert a phone number from "EPP format" described in RFC5733 to "international
     * format".
     *
     * @link https://tools.ietf.org/html/rfc5733#section-2.5
     *
     * @throws \libphonenumber\NumberParseException If not a valid EPP format phone number
     *
     * @param string $eppNumber Phone number in "EPP format" E.g., +44.7515878251
     *
     * @return string $number Phone number in "international format" E.g., +447515878251
     */
    public static function eppPhoneToInternational(string $eppNumber): string
    {
        $phone = PhoneNumberUtil::getInstance()->parse($eppNumber, null);
        $diallingCode = $phone->getCountryCode();
        $nationalNumber = $phone->getNationalNumber();

        return sprintf('+%s%s', $diallingCode, $nationalNumber);
    }

    /**
     * Normalize a phont number from local to international format.
     *
     * @param string $number Local format phone number
     * @param string|null $countryCode Country code, if known
     *
     * @return string International format phone number, if possible
     *
     * @throws \Throwable
     */
    public static function localPhoneToInternational(string $number, ?string $countryCode, bool $orFail = true): string
    {
        if (Str::startsWith($number, '+')) {
            // our work here is done
            return $number;
        }

        try {
            return (string)phone($number, $countryCode ?: []);
        } catch (Throwable $e) {
            if ($orFail) {
                throw $e;
            }

            // just return the input number
            return $number;
        }
    }

    public static function codeToCountry(?string $countryCode): ?string
    {
        return Countries::codeToName($countryCode);
    }
    /**
     * @param string|null $country
     * @return string|null
     */
    public static function countryToCode(?string $country): ?string
    {
        return Countries::nameToCode($country);
    }

    /**
     * Normalizes a given iso alpha-2 country code.
     */
    public static function normalizeCountryCode(?string $countryCode): ?string
    {
        return Countries::normalizeCode($countryCode);
    }

    /**
     * Normalizes a given state name for the given TLD.
     */
    public static function normalizeState(string $tld, ?string $state, ?string $postCode): ?string
    {
        return Countries::normalizeStateName($tld, $state, $postCode);
    }

    public static function stateNameToCode(?string $countryCode, ?string $stateName): ?string
    {
        if (!$countryCode || !$stateName) {
            return $stateName;
        }

        return Countries::stateNameToCode($countryCode, $stateName) ?? $stateName;
    }

    public static function stateCodeToName(?string $countryCode, ?string $stateCode): ?string
    {
        if (!$countryCode || !$stateCode) {
            return $stateCode;
        }

        return Countries::stateCodeToName($countryCode, $stateCode) ?? $stateCode;
    }
}
