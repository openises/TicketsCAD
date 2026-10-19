<?php
/**
 * GH#153 — built-in State / Province presets for the States / Provinces
 * wizard (states-admin.php, api/states.php, inc/states.php).
 *
 * THIS FILE IS THE ONLY COPY OF THE PRESET DATA. The wizard, the CSV
 * downloads, the US seed migration (sql/run_phase84_states_seed.php) and the
 * tests all read it from here. Do not paste a second copy anywhere.
 *
 * Shape:
 *   'presets' => [ id => [
 *       'name'    => what the wizard shows,
 *       'country' => country name (groups the list),
 *       'label'   => the field label this list usually goes with
 *                    (State / Province / Region / County ...),
 *       'source'  => where the codes and names come from,
 *       'rows'    => [ [code, name], ... ]       the base list,
 *       'options' => [ key => [ 'label' => ..., 'default' => bool,
 *                               'rows' => [ [code, name], ... ] ] ],
 *   ] ]
 *   'quick_picks' => [ id => [ 'label' => ..., 'selection' => [ presetId => [optionKeys] ] ] ]
 *
 * Codes are at most 4 characters (states_translator.code is varchar(4) and
 * every *.state column in the application is 4 wide). Names are as the cited
 * source writes them, in UTF-8. Two deliberate choices, stated here so nobody
 * "corrects" them silently:
 *
 *   - Canada uses the English forms Canada Post publishes ("Quebec", not
 *     "Québec"), consistent with the other twelve English names in the same
 *     list. The New Incident geocoder match also accepts the ISO code the
 *     geocoder returns ("CA-QC"), so either spelling from the geocoder lands.
 *   - India uses ISO 3166-2:IN codes (after the 2023-11-23 change: CG, OD,
 *     TS, UK) but the plain English names the Government of India writes,
 *     without the ISO romanisation macrons ("Bihar", not "Bihār"), because
 *     those are the names on Indian addresses and what geocoders return.
 *
 * Sources (all checked 2026-10-19):
 *   US  USPS Publication 28, Appendix B "Two-Letter State and Possession
 *       Abbreviations" (pe.usps.com/text/pub28/28apb.htm).
 *   CA  Canada Post, Addressing Guidelines, "Provinces and territories"
 *       two-letter symbols (canadapost-postescanada.ca).
 *   MX  ISO 3166-2:MX (32 federal entities; CMX replaced DIF in 2016).
 *   AU  ISO 3166-2:AU (6 states, 2 territories).
 *   NZ  ISO 3166-2:NZ (16 regions + the Chatham Islands Territory; names as
 *       amended 2022-11-29: "Greater Wellington", "Manawatū-Whanganui").
 *   GB  ISO 3166-2:GB, the four countries (ENG, NIR, SCT, WLS).
 *   IE  ISO 3166-2:IE, the 26 counties.
 *   DE  ISO 3166-2:DE, the 16 Länder, German names.
 *   BR  ISO 3166-2:BR, 26 states + Distrito Federal, Portuguese names.
 *   IN  ISO 3166-2:IN, 28 states + 8 union territories.
 *
 * Pure data: requesting this file directly produces no output (and inc/ is
 * denied over HTTP anyway).
 */

$usStates = [
    ['AL', 'Alabama'], ['AK', 'Alaska'], ['AZ', 'Arizona'], ['AR', 'Arkansas'],
    ['CA', 'California'], ['CO', 'Colorado'], ['CT', 'Connecticut'], ['DE', 'Delaware'],
    ['FL', 'Florida'], ['GA', 'Georgia'], ['HI', 'Hawaii'], ['ID', 'Idaho'],
    ['IL', 'Illinois'], ['IN', 'Indiana'], ['IA', 'Iowa'], ['KS', 'Kansas'],
    ['KY', 'Kentucky'], ['LA', 'Louisiana'], ['ME', 'Maine'], ['MD', 'Maryland'],
    ['MA', 'Massachusetts'], ['MI', 'Michigan'], ['MN', 'Minnesota'], ['MS', 'Mississippi'],
    ['MO', 'Missouri'], ['MT', 'Montana'], ['NE', 'Nebraska'], ['NV', 'Nevada'],
    ['NH', 'New Hampshire'], ['NJ', 'New Jersey'], ['NM', 'New Mexico'], ['NY', 'New York'],
    ['NC', 'North Carolina'], ['ND', 'North Dakota'], ['OH', 'Ohio'], ['OK', 'Oklahoma'],
    ['OR', 'Oregon'], ['PA', 'Pennsylvania'], ['RI', 'Rhode Island'], ['SC', 'South Carolina'],
    ['SD', 'South Dakota'], ['TN', 'Tennessee'], ['TX', 'Texas'], ['UT', 'Utah'],
    ['VT', 'Vermont'], ['VA', 'Virginia'], ['WA', 'Washington'], ['WV', 'West Virginia'],
    ['WI', 'Wisconsin'], ['WY', 'Wyoming'],
];

// The contiguous 48: the 50 without Alaska and Hawaii.
$usContiguous = array_values(array_filter($usStates, static function ($r) {
    return $r[0] !== 'AK' && $r[0] !== 'HI';
}));

$usSource = 'USPS Publication 28, Appendix B (Two-Letter State and Possession Abbreviations)';

$usOptions = [
    'dc' => [
        'label'   => 'District of Columbia (DC)',
        'default' => true,
        'rows'    => [['DC', 'District of Columbia']],
    ],
    'territories' => [
        'label'   => 'Inhabited territories (PR, VI, GU, AS, MP)',
        'default' => false,
        'rows'    => [
            ['PR', 'Puerto Rico'],
            ['VI', 'Virgin Islands'],
            ['GU', 'Guam'],
            ['AS', 'American Samoa'],
            ['MP', 'Northern Mariana Islands'],
        ],
    ],
    'armed_forces' => [
        'label'   => 'Armed forces mail codes (AA, AE, AP)',
        'default' => false,
        'rows'    => [
            ['AA', 'Armed Forces Americas (except Canada)'],
            ['AE', 'Armed Forces Africa, Canada, Europe, Middle East'],
            ['AP', 'Armed Forces Pacific'],
        ],
    ],
    'freely_associated' => [
        'label'   => 'Freely associated states (FM, MH, PW)',
        'default' => false,
        'rows'    => [
            ['FM', 'Federated States of Micronesia'],
            ['MH', 'Marshall Islands'],
            ['PW', 'Palau'],
        ],
    ],
];

$india = [
    ['AP', 'Andhra Pradesh'], ['AR', 'Arunachal Pradesh'], ['AS', 'Assam'], ['BR', 'Bihar'],
    ['CG', 'Chhattisgarh'], ['GA', 'Goa'], ['GJ', 'Gujarat'], ['HR', 'Haryana'],
    ['HP', 'Himachal Pradesh'], ['JH', 'Jharkhand'], ['KA', 'Karnataka'], ['KL', 'Kerala'],
    ['MP', 'Madhya Pradesh'], ['MH', 'Maharashtra'], ['MN', 'Manipur'], ['ML', 'Meghalaya'],
    ['MZ', 'Mizoram'], ['NL', 'Nagaland'], ['OD', 'Odisha'], ['PB', 'Punjab'],
    ['RJ', 'Rajasthan'], ['SK', 'Sikkim'], ['TN', 'Tamil Nadu'], ['TS', 'Telangana'],
    ['TR', 'Tripura'], ['UP', 'Uttar Pradesh'], ['UK', 'Uttarakhand'], ['WB', 'West Bengal'],
];

return [
    'presets' => [
        'us50' => [
            'name'    => 'United States — 50 states',
            'country' => 'United States',
            'label'   => 'State',
            'source'  => $usSource,
            'rows'    => $usStates,
            'options' => $usOptions,
        ],
        'us48' => [
            'name'    => 'United States — 48 contiguous states',
            'country' => 'United States',
            'label'   => 'State',
            'source'  => $usSource,
            'rows'    => $usContiguous,
            'options' => $usOptions,
        ],
        'ca' => [
            'name'    => 'Canada — 10 provinces and 3 territories',
            'country' => 'Canada',
            'label'   => 'Province',
            'source'  => 'Canada Post Addressing Guidelines — province and territory symbols',
            'rows'    => [
                ['AB', 'Alberta'], ['BC', 'British Columbia'], ['MB', 'Manitoba'],
                ['NB', 'New Brunswick'], ['NL', 'Newfoundland and Labrador'], ['NS', 'Nova Scotia'],
                ['NT', 'Northwest Territories'], ['NU', 'Nunavut'], ['ON', 'Ontario'],
                ['PE', 'Prince Edward Island'], ['QC', 'Quebec'], ['SK', 'Saskatchewan'],
                ['YT', 'Yukon'],
            ],
            'options' => [],
        ],
        'mx' => [
            'name'    => 'Mexico — 32 federal entities',
            'country' => 'Mexico',
            'label'   => 'State',
            'source'  => 'ISO 3166-2:MX',
            'rows'    => [
                ['AGU', 'Aguascalientes'], ['BCN', 'Baja California'], ['BCS', 'Baja California Sur'],
                ['CAM', 'Campeche'], ['CHP', 'Chiapas'], ['CHH', 'Chihuahua'],
                ['CMX', 'Ciudad de México'], ['COA', 'Coahuila de Zaragoza'], ['COL', 'Colima'],
                ['DUR', 'Durango'], ['GUA', 'Guanajuato'], ['GRO', 'Guerrero'],
                ['HID', 'Hidalgo'], ['JAL', 'Jalisco'], ['MEX', 'México'],
                ['MIC', 'Michoacán de Ocampo'], ['MOR', 'Morelos'], ['NAY', 'Nayarit'],
                ['NLE', 'Nuevo León'], ['OAX', 'Oaxaca'], ['PUE', 'Puebla'],
                ['QUE', 'Querétaro'], ['ROO', 'Quintana Roo'], ['SLP', 'San Luis Potosí'],
                ['SIN', 'Sinaloa'], ['SON', 'Sonora'], ['TAB', 'Tabasco'],
                ['TAM', 'Tamaulipas'], ['TLA', 'Tlaxcala'], ['VER', 'Veracruz de Ignacio de la Llave'],
                ['YUC', 'Yucatán'], ['ZAC', 'Zacatecas'],
            ],
            'options' => [],
        ],
        'au' => [
            'name'    => 'Australia — 6 states and 2 territories',
            'country' => 'Australia',
            'label'   => 'State',
            'source'  => 'ISO 3166-2:AU',
            'rows'    => [
                ['NSW', 'New South Wales'], ['QLD', 'Queensland'], ['SA', 'South Australia'],
                ['TAS', 'Tasmania'], ['VIC', 'Victoria'], ['WA', 'Western Australia'],
                ['ACT', 'Australian Capital Territory'], ['NT', 'Northern Territory'],
            ],
            'options' => [],
        ],
        'nz' => [
            'name'    => 'New Zealand — 16 regions',
            'country' => 'New Zealand',
            'label'   => 'Region',
            'source'  => 'ISO 3166-2:NZ',
            'rows'    => [
                ['AUK', 'Auckland'], ['BOP', 'Bay of Plenty'], ['CAN', 'Canterbury'],
                ['GIS', 'Gisborne'], ['WGN', 'Greater Wellington'], ['HKB', "Hawke's Bay"],
                ['MWT', 'Manawatū-Whanganui'], ['MBH', 'Marlborough'], ['NSN', 'Nelson'],
                ['NTL', 'Northland'], ['OTA', 'Otago'], ['STL', 'Southland'],
                ['TKI', 'Taranaki'], ['TAS', 'Tasman'], ['WKO', 'Waikato'],
                ['WTC', 'West Coast'],
            ],
            'options' => [
                'chatham' => [
                    'label'   => 'Chatham Islands Territory (CIT)',
                    'default' => false,
                    'rows'    => [['CIT', 'Chatham Islands Territory']],
                ],
            ],
        ],
        'gb' => [
            'name'    => 'United Kingdom — 4 countries',
            'country' => 'United Kingdom',
            'label'   => 'Country',
            'source'  => 'ISO 3166-2:GB',
            'rows'    => [
                ['ENG', 'England'], ['NIR', 'Northern Ireland'], ['SCT', 'Scotland'], ['WLS', 'Wales'],
            ],
            'options' => [],
        ],
        'ie' => [
            'name'    => 'Ireland — 26 counties',
            'country' => 'Ireland',
            'label'   => 'County',
            'source'  => 'ISO 3166-2:IE',
            'rows'    => [
                ['CW', 'Carlow'], ['CN', 'Cavan'], ['CE', 'Clare'], ['CO', 'Cork'],
                ['DL', 'Donegal'], ['D', 'Dublin'], ['G', 'Galway'], ['KY', 'Kerry'],
                ['KE', 'Kildare'], ['KK', 'Kilkenny'], ['LS', 'Laois'], ['LM', 'Leitrim'],
                ['LK', 'Limerick'], ['LD', 'Longford'], ['LH', 'Louth'], ['MO', 'Mayo'],
                ['MH', 'Meath'], ['MN', 'Monaghan'], ['OY', 'Offaly'], ['RN', 'Roscommon'],
                ['SO', 'Sligo'], ['TA', 'Tipperary'], ['WD', 'Waterford'], ['WH', 'Westmeath'],
                ['WX', 'Wexford'], ['WW', 'Wicklow'],
            ],
            'options' => [],
        ],
        'de' => [
            'name'    => 'Germany — 16 Länder',
            'country' => 'Germany',
            'label'   => 'State',
            'source'  => 'ISO 3166-2:DE',
            'rows'    => [
                ['BW', 'Baden-Württemberg'], ['BY', 'Bayern'], ['BE', 'Berlin'], ['BB', 'Brandenburg'],
                ['HB', 'Bremen'], ['HH', 'Hamburg'], ['HE', 'Hessen'], ['MV', 'Mecklenburg-Vorpommern'],
                ['NI', 'Niedersachsen'], ['NW', 'Nordrhein-Westfalen'], ['RP', 'Rheinland-Pfalz'],
                ['SL', 'Saarland'], ['SN', 'Sachsen'], ['ST', 'Sachsen-Anhalt'],
                ['SH', 'Schleswig-Holstein'], ['TH', 'Thüringen'],
            ],
            'options' => [],
        ],
        'br' => [
            'name'    => 'Brazil — 26 states and the Federal District',
            'country' => 'Brazil',
            'label'   => 'State',
            'source'  => 'ISO 3166-2:BR',
            'rows'    => [
                ['AC', 'Acre'], ['AL', 'Alagoas'], ['AP', 'Amapá'], ['AM', 'Amazonas'],
                ['BA', 'Bahia'], ['CE', 'Ceará'], ['DF', 'Distrito Federal'], ['ES', 'Espírito Santo'],
                ['GO', 'Goiás'], ['MA', 'Maranhão'], ['MT', 'Mato Grosso'], ['MS', 'Mato Grosso do Sul'],
                ['MG', 'Minas Gerais'], ['PA', 'Pará'], ['PB', 'Paraíba'], ['PR', 'Paraná'],
                ['PE', 'Pernambuco'], ['PI', 'Piauí'], ['RJ', 'Rio de Janeiro'], ['RN', 'Rio Grande do Norte'],
                ['RS', 'Rio Grande do Sul'], ['RO', 'Rondônia'], ['RR', 'Roraima'], ['SC', 'Santa Catarina'],
                ['SP', 'São Paulo'], ['SE', 'Sergipe'], ['TO', 'Tocantins'],
            ],
            'options' => [],
        ],
        'in' => [
            'name'    => 'India — 28 states',
            'country' => 'India',
            'label'   => 'State',
            'source'  => 'ISO 3166-2:IN (codes as changed 2023-11-23)',
            'rows'    => $india,
            'options' => [
                'union_territories' => [
                    'label'   => 'The 8 union territories',
                    'default' => true,
                    'rows'    => [
                        ['AN', 'Andaman and Nicobar Islands'], ['CH', 'Chandigarh'],
                        ['DH', 'Dadra and Nagar Haveli and Daman and Diu'], ['DL', 'Delhi'],
                        ['JK', 'Jammu and Kashmir'], ['LA', 'Ladakh'], ['LD', 'Lakshadweep'],
                        ['PY', 'Puducherry'],
                    ],
                ],
            ],
        ],
    ],

    // One click each: the wizard sets these presets and options and goes on.
    'quick_picks' => [
        'us48'             => ['label' => 'US 48 contiguous + DC',              'selection' => ['us48' => ['dc']]],
        'us50'             => ['label' => 'US 50 + DC',                         'selection' => ['us50' => ['dc']]],
        'us50_territories' => ['label' => 'US 50 + DC + territories',           'selection' => ['us50' => ['dc', 'territories']]],
        'us_all_postal'    => ['label' => 'Every US postal code',               'selection' => ['us50' => ['dc', 'territories', 'armed_forces', 'freely_associated']]],
        'canada'           => ['label' => 'Canada',                             'selection' => ['ca' => []]],
        'us_canada'        => ['label' => 'US 50 + DC + Canada',                'selection' => ['us50' => ['dc'], 'ca' => []]],
        'mexico'           => ['label' => 'Mexico',                             'selection' => ['mx' => []]],
        'north_america'    => ['label' => 'US 50 + DC + territories + Canada + Mexico',
                               'selection' => ['us50' => ['dc', 'territories'], 'ca' => [], 'mx' => []]],
    ],
];
