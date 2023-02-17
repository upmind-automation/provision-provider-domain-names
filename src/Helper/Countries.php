<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Helper;

class Countries
{
    protected static $countries = [
        'AC' => 'Ascension',
        'AD' => 'Andorra',
        'AE' => 'United Arab Emirates',
        'AF' => 'Afghanistan',
        'AG' => 'Antigua and Barbuda',
        'AI' => 'Anguilla',
        'AL' => 'Albania',
        'AM' => 'Armenia',
        'AN' => 'Netherland Antilles',
        'AO' => 'Angola',
        'AQ' => 'Antarctica',
        'AR' => 'Argentina',
        'AS' => 'American Samoa',
        'AT' => 'Austria',
        'AU' => 'Australia',
        'AW' => 'Aruba',
        'AZ' => 'Azerbaidjan',
        'BA' => 'Bosnia-Herzegovina',
        'BB' => 'Barbados',
        'BD' => 'Banglades',
        'BE' => 'Belgium',
        'BF' => 'Burkina Faso',
        'BG' => 'Bulgaria',
        'BH' => 'Bahrain',
        'BI' => 'Burundi',
        'BJ' => 'Benin',
        'BM' => 'Bermuda',
        'BN' => 'Brunei Darussalam',
        'BO' => 'Bolivia',
        'BR' => 'Brazil',
        'BS' => 'Bahamas',
        'BT' => 'Buthan',
        'BV' => 'Bouvet Island',
        'BW' => 'Botswana',
        'BY' => 'Belarus',
        'BZ' => 'Belize',
        'CA' => 'Canada',
        'CC' => 'Cocos (Keeling) Islands',
        'CF' => 'Central African Republic',
        'CG' => 'Congo',
        'CH' => 'Switzerland',
        'CI' => 'Ivory Coast',
        'CK' => 'Cook Islands',
        'CL' => 'Chile',
        'CM' => 'Cameroon',
        'CN' => 'China',
        'CO' => 'Colombia',
        'CR' => 'Costa Rica',
        'CS' => 'Czechoslovakia',
        'CU' => 'Cuba',
        'CV' => 'Cape Verde',
        'CX' => 'Christmas Island',
        'CY' => 'Cyprus',
        'CZ' => 'Czech Republic',
        'DE' => 'Germany',
        'DJ' => 'Djibouti',
        'DK' => 'Denmark',
        'DM' => 'Dominica',
        'DO' => 'Dominican Republic',
        'DZ' => 'Algeria',
        'EC' => 'Ecuador',
        'EE' => 'Estonia',
        'EG' => 'Egypt',
        'EH' => 'Western Sahara',
        'ES' => 'Spain',
        'ET' => 'Ethiopia',
        'FI' => 'Finland',
        'FJ' => 'Fiji',
        'FK' => 'Falkland Islands (Malvinas)',
        'FM' => 'Micronesia',
        'FO' => 'Faroe Islands',
        'FR' => 'France',
        'GA' => 'Gabon',
        'GB' => 'Great Britain',
        'GD' => 'Grenada',
        'GE' => 'Georgia',
        'GH' => 'Ghana',
        'GI' => 'Gibraltar',
        'GL' => 'Greenland',
        'GP' => 'Guadeloupe (French)',
        'GQ' => 'Equatorial Guinea',
        'GF' => 'Guyana (French)',
        'GM' => 'Gambia',
        'GN' => 'Guinea',
        'GR' => 'Greece',
        'GS' => 'South Georgia and South Sandwich Islands',
        'GT' => 'Guatemala',
        'GU' => 'Guam (US)',
        'GW' => 'Guinea Bissau',
        'GY' => 'Guyana',
        'HK' => 'Hong Kong',
        'HM' => 'Heard and McDonald Islands',
        'HN' => 'Honduras',
        'HR' => 'Croatia',
        'HT' => 'Haiti',
        'HU' => 'Hungary',
        'ID' => 'Indonesia',
        'IE' => 'Ireland',
        'IL' => 'Israel',
        'IN' => 'India',
        'IO' => 'British Indian Ocean Territory',
        'IQ' => 'Iraq',
        'IR' => 'Iran',
        'IS' => 'Iceland',
        'IT' => 'Italy',
        'JM' => 'Jamaica',
        'JO' => 'Jordan',
        'JP' => 'Japan',
        'KE' => 'Kenya',
        'KG' => 'Kirgistan',
        'KH' => 'Cambodia',
        'KI' => 'Kiribati',
        'KM' => 'Comoros',
        'KN' => 'Saint Kitts Nevis Anguilla',
        'KP' => 'North Korea',
        'KR' => 'South Korea',
        'KW' => 'Kuwait',
        'KY' => 'Cayman Islands',
        'KZ' => 'Kazachstan',
        'LA' => 'Laos',
        'LB' => 'Lebanon',
        'LC' => 'Saint Lucia',
        'LI' => 'Liechtenstein',
        'LK' => 'Sri Lanka',
        'LR' => 'Liberia',
        'LS' => 'Lesotho',
        'LT' => 'Lithuania',
        'LU' => 'Luxembourg',
        'LV' => 'Latvia',
        'LY' => 'Libya',
        'MA' => 'Morocco',
        'MC' => 'Monaco',
        'MD' => 'Moldavia',
        'MG' => 'Madagascar',
        'MH' => 'Marshall Islands',
        'ML' => 'Mali',
        'MM' => 'Myanmar',
        'MN' => 'Mongolia',
        'MO' => 'Macau',
        'MP' => 'Northern Mariana Islands',
        'MQ' => 'Martinique (French)',
        'MR' => 'Mauritania',
        'MS' => 'Montserrat',
        'MT' => 'Malta',
        'MU' => 'Mauritius',
        'MV' => 'Maldives',
        'MW' => 'Malawi',
        'MX' => 'Mexico',
        'MY' => 'Malaysia',
        'MZ' => 'Mozambique',
        'NA' => 'Namibia',
        'NC' => 'New Caledonia (French)',
        'NE' => 'Niger',
        'NF' => 'Norfolk Island',
        'NG' => 'Nigeria',
        'NI' => 'Nicaragua',
        'NL' => 'Netherlands',
        'NO' => 'Norway',
        'NP' => 'Nepal',
        'NR' => 'Nauru',
        'NT' => 'Neutral Zone',
        'NU' => 'Niue',
        'NZ' => 'New Zealand',
        'OM' => 'Oman',
        'PA' => 'Panama',
        'PE' => 'Peru',
        'PF' => 'Polynesia (French)',
        'PG' => 'Papua New',
        'PH' => 'Philippines',
        'PK' => 'Pakistan',
        'PL' => 'Poland',
        'PM' => 'Saint Pierre and Miquelon',
        'PN' => 'Pitcairn',
        'PT' => 'Portugal',
        'PR' => 'Puerto Rico (US)',
        'PW' => 'Palau',
        'PY' => 'Paraguay',
        'QA' => 'Qatar',
        'RE' => 'Reunion (French)',
        'RO' => 'Romania',
        'RU' => 'Russian Federation',
        'RW' => 'Rwanda',
        'SA' => 'Saudi Arabia',
        'SB' => 'Solomon Islands',
        'SC' => 'Seychelles',
        'SD' => 'Sudan',
        'SE' => 'Sweden',
        'SG' => 'Singapore',
        'SH' => 'Saint Helena',
        'SI' => 'Slovenia',
        'SJ' => 'Svalbard and Jan Mayen Islands',
        'SK' => 'Slovak Republic',
        'SL' => 'Sierra Leone',
        'SM' => 'San Marino',
        'SN' => 'Senegal',
        'SO' => 'Somalia',
        'SR' => 'Suriname',
        'ST' => 'Saint Tome and Principe',
        'SU' => 'Soviet Union',
        'SV' => 'El Salvador',
        'SY' => 'Syria',
        'SZ' => 'Swaziland',
        'TC' => 'Turks and Caicos Islands',
        'TD' => 'Chad',
        'TF' => 'French Southern Territory',
        'TG' => 'Togo',
        'TH' => 'Thailand',
        'TJ' => 'Tadjikistan',
        'TK' => 'Tokelau',
        'TM' => 'Turkmenistan',
        'TN' => 'Tunisia',
        'TO' => 'Tonga',
        'TP' => 'East Timor',
        'TR' => 'Turkey',
        'TT' => 'Trinidad and Tobago',
        'TV' => 'Tuvalu',
        'TW' => 'Taiwan',
        'TZ' => 'Tanzania',
        'UA' => 'Ukraine',
        'UG' => 'Uganda',
        'UK' => 'United Kingdom',
        'UM' => 'US Minor Outlying Islands',
        'US' => 'United States',
        'UY' => 'Uruguay',
        'UZ' => 'Uzbekistan',
        'VA' => 'Vatican City State',
        'VC' => 'Saint Vincent and Grenadines',
        'VE' => 'Venezuela',
        'VG' => 'Virgin Islands (British)',
        'VI' => 'Virgin Islands (US)',
        'VN' => 'Vietnam',
        'VU' => 'Vanuatu',
        'WF' => 'Wallis and Futuna Islands',
        'WS' => 'Samoa',
        'YE' => 'Yemen',
        'YU' => 'Yugoslavia',
        'ZA' => 'South Africa',
        'ZM' => 'Zambia',
        'ZR' => 'Zaire',
        'ZW' => 'Zimbabwe',
    ];

    /**
     * Obtain the name of the given country code.
     *
     * @param string $countryCode ISO alpha-2 country code
     *
     * @return string|null Country name, or null if unknown
     */
    public static function codeToName($countryCode): ?string
    {
        $countryCode = static::normalizeCode($countryCode);

        return static::$countries[$countryCode] ?? null;
    }

    /**
     * Obtain the code of the given country name.
     *
     * @param string $countryName Country name
     *
     * @return string|null ISO alpha-2 country code, or null if unknown
     */
    public static function nameToCode($countryName): ?string
    {
        $search = strtolower(trim($countryName ?? ''));
        if (empty($search)) {
            return null;
        }

        $countries = array_map('strtolower', static::$countries);

        if ($countryCode = array_search($search, $countries)) {
            return $countryCode;
        }

        // return closest match using levenshtein ??

        return null;
    }

    /**
     * Normalize the given country code.
     */
    public static function normalizeCode($countryCode): ?string
    {
        $countryCode = strtoupper(trim($countryCode ?? ''));

        if (empty($countryCode)) {
            return null;
        }

        switch ($countryCode) {
            case 'UK':
                return 'GB';
            default:
                return $countryCode;
        }
    }

    /**
     * @param string $countryCode
     * @param string $stateName
     * @return string|null
     */
    public static function stateNameToCode(string $countryCode, string $stateName): ?string
    {
        if (!$countryCode = self::normalizeCode($countryCode)) {
            return null;
        }

        if (!$stateName = strtolower(trim($stateName ?? ''))) {
            return null;
        }

        $countries = new \PragmaRX\Countries\Package\Countries();
        return $countries->where('cca2', $countryCode)
            ->first()
            ->hydrateStates()
            ->states
            ->first(function ($state) use ($stateName) {
                return strtolower($state->name) == $stateName;
            })
            ->postal ?? null;
    }

    public static function stateCodeToName(string $countryCode, string $stateCode): ?string
    {
        if (!$countryCode = self::normalizeCode($countryCode)) {
            return null;
        }

        if (!$stateCode = strtolower(trim($stateCode ?? ''))) {
            return null;
        }

        $countries = new \PragmaRX\Countries\Package\Countries();
        return $countries->where('cca2', $countryCode)
                ->first()
                ->hydrateStates()
                ->states
                ->first(function ($state) use ($stateCode) {
                    return strtolower($state->postal) == $stateCode;
                })
                ->name ?? null;
    }
}
