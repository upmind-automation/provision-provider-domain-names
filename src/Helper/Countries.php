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
     * Normalize the given country code to upper-case.
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

    /**
     * Normalize state name
     */
    public static function normalizeStateName(string $tld, ?string $state, ?string $postCode): ?string
    {
        if (empty($state)) {
            return null;
        }

        if (Utils::normalizeTld($tld) === 'es') {
            $state = self::accentsToAscii(strtoupper(trim($state)));

            if (empty($postCode)) {
                return $state;
            }

            /** @link https://support.openprovider.eu/hc/en-us/articles/216647268--es-List-of-approved-provinces */
            $postCodeMap = [
                '01' => 'ARABA',
                '02' => 'ALBACETE',
                '03' => 'ALICANTE',
                '04' => 'ALMERIA',
                '05' => 'AVILA',
                '06' => 'BADAJOZ',
                '07' => 'ILLES BALEARS',
                '08' => 'BARCELONA',
                '09' => 'BURGOS',
                '10' => 'CACERES',
                '11' => 'CADIZ',
                '12' => 'CASTELLON',
                '13' => 'CIUDAD REAL',
                '14' => 'CORDOBA',
                '15' => 'CORUÑA, A',
                '16' => 'CUENCA',
                '17' => 'GIRONA',
                '18' => 'GRANADA',
                '19' => 'GUADALAJARA',
                '20' => 'GIPUZKOA',
                '21' => 'HUELVA',
                '22' => 'HUESCA',
                '23' => 'JAEN',
                '24' => 'LEON',
                '25' => 'LLEIDA',
                '26' => 'RIOJA, LA',
                '27' => 'LUGO',
                '28' => 'MADRID',
                '29' => 'MALAGA',
                '30' => 'MURCIA',
                '31' => 'NAVARRA',
                '32' => 'OURENSE',
                '33' => 'ASTURIAS',
                '34' => 'PALENCIA',
                '35' => 'PALMAS, LAS',
                '36' => 'PONTEVEDRA',
                '37' => 'SALAMANCA',
                '38' => 'SANTA CRUZ DE TENERIFE',
                '39' => 'CANTABRIA',
                '40' => 'SEGOVIA',
                '41' => 'SEVILLA',
                '42' => 'SORIA',
                '43' => 'TARRAGONA',
                '44' => 'TERUEL',
                '45' => 'TOLEDO',
                '46' => 'VALENCIA',
                '47' => 'VALLADOLID',
                '48' => 'BIZKAIA',
                '49' => 'ZAMORA',
                '50' => 'ZARAGOZA',
                '51' => 'CEUTA',
                '52' => 'MELILLA',
            ];

            return $postCodeMap[substr($postCode, 0, 2)] ?? $state;
        }

        return $state;
    }

    /**
     * Convert accented characters to their ASCII equivalents.
     *
     * @param string $string E.g., Almería
     *
     * @return string E.g., Almeria
     */
    public static function accentsToAscii(string $string): string
    {
        $normalizeChars = [
            'Š' => 'S',
            'š' => 's',
            'Ð' => 'Dj',
            'Ž' => 'Z',
            'ž' => 'z',
            'À' => 'A',
            'Á' => 'A',
            'Â' => 'A',
            'Ã' => 'A',
            'Ä' => 'A',
            'Å' => 'A',
            'Æ' => 'A',
            'Ç' => 'C',
            'È' => 'E',
            'É' => 'E',
            'Ê' => 'E',
            'Ë' => 'E',
            'Ì' => 'I',
            'Í' => 'I',
            'Î' => 'I',
            'Ï' => 'I',
            'Ñ' => 'N',
            'Ń' => 'N',
            'Ò' => 'O',
            'Ó' => 'O',
            'Ô' => 'O',
            'Õ' => 'O',
            'Ö' => 'O',
            'Ø' => 'O',
            'Ù' => 'U',
            'Ú' => 'U',
            'Û' => 'U',
            'Ü' => 'U',
            'Ý' => 'Y',
            'Þ' => 'B',
            'ß' => 'Ss',
            'à' => 'a',
            'á' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'å' => 'a',
            'æ' => 'a',
            'ç' => 'c',
            'è' => 'e',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'ì' => 'i',
            'í' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ð' => 'o',
            'ñ' => 'n',
            'ń' => 'n',
            'ò' => 'o',
            'ó' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ø' => 'o',
            'ù' => 'u',
            'ú' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ý' => 'y',
            'ý' => 'y',
            'þ' => 'b',
            'ÿ' => 'y',
            'ƒ' => 'f',
            'ă' => 'a',
            'î' => 'i',
            'â' => 'a',
            'ș' => 's',
            'ț' => 't',
            'Ă' => 'A',
            'Î' => 'I',
            'Â' => 'A',
            'Ș' => 'S',
            'Ț' => 'T',
        ];

        return strtr($string, $normalizeChars);
    }
}
