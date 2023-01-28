<?php

/**
 * Project:     MijnDomeinReseller (MDR) API
 * File:        mdr.php
 * @author      Jeroen de Jong <jeroen@telartis.nl>
 * @copyright   2016-2017 Telartis BV
 * @version     1.01
 * @link        https://www.mijndomeinreseller.nl/api/
 *
 * With this PHP class you can easily access the MijnDomeinReseller API.
 * You must of course be a MDR customer to be able to use it.
 *
 */


/*

=== Example code:

$mdr = new \telartis\mijndomeinreseller\mdr($user, $pass);

$domain = 'example.com';

$details = $mdr->domain_get_details($domain);

$result = $mdr->dns_record_del($domain, $record_id);
if ($mdr->has_error($result)) {
    die($mdr->get_error($result));
}

$zone_records = $mdr->dns_get_details($domain);
if ($zone_records === false) {
    die($mdr->get_error());
}
foreach ($zone_records as $row) {
    if ($row['record_type'] == 'A') {
        ...
    } elseif ($row['record_type'] == 'MX') {
        if (!strlen($row['subdomain'])) {
            if ($row['destination'] == 'fallback.example.com') ...
        }
    }
}


=== Functions:

__construct($user, $pass, $verbose = false)
_dbg($var, $name)
_add_error($error)
_dns_record_add_modify_data($domain, $subdomain, $record_type, $destination, $mx_pref, $weight, $port)
_fix_subdomain($domain, $subdomain)

do_webservice($data, $msg = ''): array result
print_result($result, $info = '', $only_errors = false, $extra_info = ''): void echo result when verbose is set
has_error($result = []): bool
get_error($result = []): string error
domain_split($domain): array($private, $public)

contact_add($contact): array result
contact_get_details($contact_id): array result
contact_list($sort = '', $order = 0): array|false

dns_get_details($domain, $extra_info = ''): array zone_records [subdomain, record_id, record_type, destination, mx_pref, weight, port] or false
dns_record_get($domain, $subdomain, $record_type, $mx_pref = ''): int record_id, 0 when not found or array with record_id's
dns_record_get_by_id($domain, $record_id): array zone_record
dns_record_del($domain, $record_id): array result
dns_record_del_by_value($domain, $subdomain, $record_type, $mx_pref = ''): array result
dns_record_add($domain, $subdomain, $record_type, $destination, $mx_pref = '', $weight = '', $port = ''): array result
dns_record_modify($domain, $record_id, $subdomain, $record_type, $destination, $mx_pref = '', $weight = '', $port = ''): array result
dns_record_add_or_modify($domain, $subdomain, $record_type, $destination, $mx_pref = ''): array result
dns_get_spf($domain): string
dns_set_spf_include($domain, $spf_include): array result
dns_ttl_get($domain): int ttl
dns_ttl_modify($domain, $ttl = 900): array result
dns_set_web_records($domain, $destination): bool
dns_del_mx_records($domain): bool
dns_set_mx_records_google($domain): bool

domain_auth_info($domain): array result
domain_delete($domain): array result
domain_get_details($domain): array result
domain_authkey($domain): string
domain_list($tld = '', $sort = '', $order = 0, $begin = ''): array|false / [domein, registrant, admin, tech, verloopdatum, status, autorenew]
domain_list_delete(): array|false / [domein, tld, datum]
domain_modify_contacts($domain, $registrant_id, $admin_id, $tech_id, $bill_id): array result
domain_modify_ns($domain, $gebruik_dns = false, $ns_id = '', $dns_template = ''): array result
domain_push_request($domain, $authkey): array result
domain_register($domain, $gebruik_dns, $dns_template, $registrant_id, $admin_id, $tech_id, $bill_id): array result
domain_renew($domain, $duur): array result
domain_restore($domain): array result
domain_set_autorenew($domain, $autorenew, $registrant_approve = true): array result
domain_set_lock($domain, $set_lock): array result
domain_transfer($domain, $authkey, $gebruik_dns, $dns_template, $registrant_id, $admin_id, $tech_id, $bill_id): array result

nameserver_add($ns1, $ns2, $ns3 = '', $ns1_ip = '', $ns2_ip = '', $ns3_ip = ''): array result
nameserver_list(): array result

dig($domain): array

newgtld_list(): array [tld, sunrise_start, sunrise_end, landrush, golive, is_live]

tld_list(): array
tld_get_details($tld): array result
_tld_price(array $details, string $key): int
purchase_price(array $details): int
transfer_price(array $details): int
renew_price(array $details): int

TBD:
dns_template_*
dnssec_*
nameserver_glue_*
transfer_details
transfer_list
whois

*/

namespace telartis\mijndomeinreseller;

class mdr
{
    public $user     = '';
    public $pass     = '';
    public $verbose  = false;
    public $debug    = false;
    public $url      = '';
    public $response = '';
    public $result   = [];
    public $errors   = '';

    /**
     * Constructor
     *
     * @param string   $user     Username
     * @param string   $pass     Password
     * @param boolean  $verbose  Optional, default false
     */
    public function __construct($user, $pass, $verbose = false)
    {
        $this->user    = $user;
        $this->pass    = $pass;
        $this->verbose = $verbose;
    }

    /**
     * Debug string with variable type/name
     *
     * @param  string   $var
     * @param  string   $name
     * @return string
     */
    private function _dbg($var, $name)
    {
        ob_start();
        var_dump($var);
        $dump = trim(ob_get_contents());
        ob_end_clean();

        return "$name:\n".$dump."\n";
    }

    /**
     * Add an error to the result list
     *
     * @param string   $error
     */
    private function _add_error($error)
    {
        $this->result['errcount']  = 1;
        $this->result['errno1']    = 0;
        $this->result['errnotxt1'] = $error;
    }

    /**
     * Fix subdomain
     *
     * @param  string   $domain
     * @param  string   $subdomain
     * @return string
     */
    private function _fix_subdomain($domain, $subdomain)
    {
        $subdomain = trim($subdomain);

        // remove trailing dot:
        if (substr($subdomain, -1) == '.') {
            $subdomain = substr($subdomain, 0, -1);
        }

        // remove domain from subdomain if it exists:
        $subdomain = preg_replace('/\.'.preg_quote($domain, '/').'$/', '', $subdomain);

        if ($subdomain == $domain || !strlen($subdomain)) {
            $subdomain = '@';
        }

        return $subdomain;
    }

    /**
     * DNS record add or modify data array
     *
     * @param  string   $domain
     * @param  string   $subdomain
     * @param  string   $record_type
     * @param  string   $destination
     * @param  integer  $mx_pref
     * @param  integer  $weight
     * @param  integer  $port
     * @return array
     */
    private function _dns_record_add_modify_data($domain, $subdomain, $record_type, $destination, $mx_pref, $weight, $port)
    {
        [$data['domein'], $data['tld']] = $this->domain_split($domain);

        $subdomain = $this->_fix_subdomain($domain, $subdomain);

        $data['host']    = $subdomain;    // het te wijzigen of toe te voegen A,MX,CNAME record ('@' indien er geen sprake is van subdomein bv: 'example.com')
        $data['type']    = $record_type;  // het type (A,MX,CNAME) van het te wijzigen of toe te voegen record
        $data['address'] = $destination;  // de nieuwe waarde voor het record
        if (strlen($mx_pref)) {
            $data['priority'] = $mx_pref; // de preference van het SRV/MX record indien deze gewijzigd of toegevoegd wordt
        }
        if (strlen($weight)) {
            $data['weight'] = $weight;    // alleen SRV-record
        }
        if (strlen($port)) {
            $data['port'] = $port;        // alleen SRV-record
        }

        return $data;
    }

    /**
     * Voer een webservice uit
     *
     * @param  array    $data
     * @param  string   $msg   Optional
     * @return array result
     */
    public function do_webservice($data, $msg = '')
    {
        $data['user']     = $this->user;
        $data['pass']     = md5($this->pass);
        $data['authtype'] = 'md5';

        $this->url = 'https://manager.mijndomeinreseller.nl/api/?'.http_build_query($data);

        $this->result = [];
        $this->result['command'] = $data['command'];

        $ch = curl_init();
        if ($ch === false) {
            $this->_add_error('cURL init error');
        } else {
            curl_setopt($ch, CURLOPT_URL, $this->url);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            $this->response = curl_exec($ch);
            if ($this->response === false) {
                $this->_add_error('cURL execute error '.curl_errno($ch).': '.curl_error($ch));
            } else {
                foreach (explode("\n", $this->response) as $line) {
                    if (substr($line[0], 1, 1) != ';') {  // ignore comments
                        [$name, $value] = explode('=', $line, 2);
                        $this->result[trim($name)] = trim($value);
                    }
                }
                if ($this->debug) {
                    echo 'Response debug: '.$this->url."\n".
                        $this->_dbg($data, 'Data').
                        $this->_dbg($this->result, 'Result').
                        $this->_dbg($this->response, 'Response');
                }
                if (!array_key_exists('errcount', $this->result) || !array_key_exists('done', $this->result)) {
                    $this->_add_error(
                        'Response error: '.$this->url."\n".
                        $this->_dbg($data, 'Data').
                        $this->_dbg($this->result, 'Result').
                        $this->_dbg($this->response, 'Response')
                    );
                }
            }
            curl_close($ch);
        }

        if (strlen($msg)) {
            $this->print_result($this->result, $msg);
        }

        if ($this->has_error($this->result)) {
            $this->errors .= $this->get_error()."\n";
        }

        return $this->result;
    }

    /**
     * Print het resultaat
     *
     * @param  array    $result
     * @param  string   $info         Optional, default ''
     * @param  boolean  $only_errors  Optional, default false
     * @param  string   $extra_info   Optional, default ''
     * @return void
     */
    public function print_result($result, $info = '', $only_errors = false, $extra_info = '')
    {
        if ($this->verbose) {
            $command = array_key_exists('command', $result) ? $result['command'] : 'Unknown command';
            $msg = $command.': '.(strlen($info) ? $info.(strlen($extra_info) ? " ($extra_info)" : '').' => ' : '');
            if ($this->has_error($result)) {
                echo $msg.$this->get_error($result);
            } elseif (!$only_errors) {
                echo $msg.'OK'."\n";
            }
        }
    }

    /**
     * Heeft het resultaat een fout?
     *
     * @param  array    $result  Optional, default []
     * @return bool
     */
    public function has_error($result = [])
    {
        if (!$result) {
            $result = $this->result;
        }

        return is_array($result) && array_key_exists('errcount', $result) ? intval($result['errcount']) > 0 : true;
    }

    /**
     * Geeft de error string uit het resultaat terug
     *
     * @param  array    $result  Optional, default []
     * @return string            Error message
     */
    public function get_error($result = [])
    {
        if (!$result) {
            $result = $this->result;
        }
        $errors = [];
        if (is_array($result) && array_key_exists('errcount', $result)) {
            $errcount = (int) $result['errcount'];
            for ($i = 1; $i <= $errcount; $i++) {
                $errors[] = $result['errno'.$i].': '.$result['errnotxt'.$i];
            }
        } else {
            $errors[] = 'Unknown error';
        }

        return ($errors ? implode("\n", $errors)."\n" : '');
    }

    /**
     * Split een domein in private en public (=tld en soms sld+tld) gedeelte
     *
     * 'mail.example.nl' => ['mail.example', 'nl']
     * 'example.co.uk' => ['example', 'co.uk']
     * @param  string   $domain
     * @return array($private, $public)
     */
    public function domain_split($domain)
    {
        $domain = trim($domain);
        // Remove trailing dot from fully-qualified (unambiguous) absolute domain names:
        if (substr($domain, -1) == '.') {
            $domain = substr($domain, 0, -1);
        }
        // See Public Suffix List at: http://publicsuffix.org/
        $parts = explode('.', $domain);
        $tld = array_pop($parts);
        if (in_array($tld, explode('|', 'ar|au|bd|bn|ck|cy|do|eg|er|et|fj|fk|gt|gu|il|jm|ke|kh|kw|mm|mt|mz|ni|np|nz|om|pg|py|qa|sv|tr|uk|uy|ve|ye|yu|za|zm|zw'))) {
            // Only third level domains like *.co.uk
            $public = array_pop($parts).'.'.$tld;
        } else {
            // Only second level domains like *.com, *.nl
            $public = $tld;
        }
        $private = implode('.', $parts);

        return [$private, $public];
    }

    /**
     * Creëer een nieuw contact
     *
     * @param  array    $contact
     * @return array result
     */
    public function contact_add($contact)
    {
        $data['command'] = __FUNCTION__;

        foreach ($contact as $key => $value) {
            $data[$key] = $value;
        }

        /*
        $contact['bedrijfsnaam']  = '';  // Bedrijfsnaam van contact                        Nee
        $contact['rechtsvorm']    = '';  // Rechtsvorm van contact (bijlage 2)              Nee
        $contact['regnummer']     = '';  // Registratienummer van bedrijfsnaam van contact  Ja, als rechtsvorm is: bv, bv i/o, cv, coop, eenmanszaak, owm, stichting, nv of vof
        $contact['voorletter']    = '';  // Voorletter van contact                          Ja
        $contact['tussenvoegsel'] = '';  // Tussenvoegsel van contact                       Nee
        $contact['achternaam']    = '';  // Achternaam van contact                          Ja
        $contact['straat']        = '';  // Straat van contact                              Ja
        $contact['huisnr']        = '';  // Huisnummer van contact                          Ja
        $contact['huisnrtoev']    = '';  // Huisnummer toevoeging van contact               Nee
        $contact['postcode']      = '';  // Postcode van contact                            Ja
        $contact['plaats']        = '';  // Plaats van contact                              Ja
        $contact['land']          = '';  // Landcode (bijlage 3) van contact                Ja
        $contact['email']         = '';  // Emailadres van contact                          Ja
        $contact['tel']           = '';  // Telefoonnummer van contact (zonder landcode)    Ja
        */

        return $this->do_webservice($data);
    }

    /**
     * Toon de details van een contact
     *
     * @param  string   $contact_id
     * @return array result
     */
    public function contact_get_details($contact_id)
    {
        $data['command'] = __FUNCTION__;

        $data['contact_id'] = $contact_id;

        return $this->do_webservice($data);
    }

    /**
     * Toon een overzicht van de contacts uit het account
     *
     * @param  string   $sort  Optional, Default ''. bedrijfsnaam/voornaam/tussenvoegsel/achternaam/email/tel
     * @param  integer  $order Optional, Default 0=ASC. 1=DESC
     * @return array or false
     */
    public function contact_list($sort = '', $order = 0)
    {
        $data['command'] = __FUNCTION__;

        if ($sort) {
            $data['sort'] = $sort;
        }

        if ($order) {
            $data['order'] = $order;
        }

        $result = $this->do_webservice($data);

        if ($this->has_error($result)) {
            $r = false;
        } else {
            $r = [];
            $total = (int) $result['contactcount'];
            for ($i = 0; $i < $total; $i++) {
                $r[] = [
                    'contact_id'            => $result["contact_id[$i]"],            // ContactID
                    'contact_bedrijfsnaam'  => $result["contact_bedrijfsnaam[$i]"],  // Bedrijfsnaam van contact
                    'contact_voorletter'    => $result["contact_voorletter[$i]"],    // Voorletter van contact
                    'contact_tussenvoegsel' => $result["contact_tussenvoegsel[$i]"], // Tussenvoegsel van contact
                    'contact_achternaam'    => $result["contact_achternaam[$i]"],    // Achternaam van contact
                    'contact_straat'        => $result["contact_straat[$i]"],        // Straat van contact
                    'contact_huisnr'        => $result["contact_huisnr[$i]"],        // Huisnummer van contact
                    'contact_postcode'      => $result["contact_postcode[$i]"],      // Postcode van contact
                    'contact_plaats'        => $result["contact_plaats[$i]"],        // Plaats van contact
                    'contact_land'          => $result["contact_land[$i]"],          // Landcode (bijlage 3) van contact
                    'contact_email'         => $result["contact_email[$i]"],         // Emailadres van contact
                    'contact_tel'           => $result["contact_tel[$i]"],           // Telefoonnummer van contact
                ];
            }
        } // endif

        return $r;
    }

    /**
     * Geeft een lijst met zonefile instellingen van een domein
     *
     * @param  string   $domain
     * @param  string   $extra_info  Optional, default ''
     * @return zone_records [subdomain, record_id, record_type, destination, mx_pref, weight, port] or false
     */
    public function dns_get_details($domain, $extra_info = '')
    {
        $data['command'] = __FUNCTION__;

        [$data['domein'], $data['tld']] = $this->domain_split($domain);

        $result = $this->do_webservice($data);
        $this->print_result($result, $domain, true, $extra_info);

        if ($this->has_error($result)) {
            $r = false;
        } else {
            $r = [];
            $total = (int) $result['recordcount'];
            for ($i = 0; $i < $total; $i++) {
                $r[] = [
                    'record_id'   => $result["record_id[$i]"],
                    'subdomain'   => $this->_fix_subdomain($domain, $result["host[$i]"]), // het locale deel van de zonefile (bv: test.example.nl)
                    'record_type' => $result["type[$i]"],      // het record type van de zonefile (bv: A)
                    'destination' => $result["address[$i]"],   // de bestemming van de zonefile (bv: 217.115.192.5)
                    'mx_pref'     => $result["priority[$i]"],  // de prioriteit indien sprake is van MX/SRV records (bv: 10)
                    'weight'      => $result["weight[$i]"],    // Weight van het record (enkel voor SRV)
                    'port'        => $result["port[$i]"],      // Port van het record (enkel voor SRV)
                ];
            }

            $sort = [];
            foreach ($r as $key => $row) {
                $sort[$key] = $row['record_type'].str_pad($row['mx_pref'], 3, '0', STR_PAD_LEFT).$row['subdomain'];
            }
            array_multisort($sort, $r);

        } // endif

        return $r;
    }

    /**
     * Zoek record_id(s) in de zonefile op basis van subdomain, record_type en optioneel mx_pref
     *
     * @param  string   $domain
     * @param  string   $subdomain
     * @param  string   $record_type
     * @param  string   $mx_pref      Optional, default ''
     * @return integer record_id or array with record_id's
     */
    public function dns_record_get($domain, $subdomain, $record_type, $mx_pref = '')
    {
        $result = [];
        $subdomain = $this->_fix_subdomain($domain, $subdomain);
        $zone_records = $this->dns_get_details($domain);
        if ($zone_records !== false) {
            foreach ($zone_records as $row) {
                if ($row['subdomain'] == $subdomain && $row['record_type'] == $record_type && (!strlen($mx_pref) || $row['mx_pref'] == $mx_pref)) {
                    $result[] = $row['record_id'];
                }
            }
        }
        if (!$result) {
            return 0;
        } elseif (count($result) > 1) {
            return $result;
        } else {
            return $result[0];
        }

        return $result;
    }

    /**
     * Geeft de gegevens van een record in de zonefile van een domein op basis van het ID
     *
     * @param  string   $domain
     * @param  integer  $record_id
     * @return array zone_record
     */
    public function dns_record_get_by_id($domain, $record_id)
    {
        $result = [];

        $zone_records = $this->dns_get_details($domain);
        if ($zone_records !== false) {
            foreach ($zone_records as $row) {
                if ($row['record_id'] == $record_id) {
                    $result = $row;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Verwijdert een record in de zonefile van een domein
     *
     * @param  string   $domain
     * @param  integer  $record_id
     * @return array result
     */
    public function dns_record_del($domain, $record_id)
    {
        $data['command'] = __FUNCTION__;

        [$data['domein'], $data['tld']] = $this->domain_split($domain);

        $data['record_id'] = $record_id;

        return $this->do_webservice($data, $domain.' '.$record_id);
    }

    /**
     * Verwijdert een record in de zonefile van een domein op basis van subdomain, record_type en optioneel mx_pref
     *
     * @param  string   $domain
     * @param  string   $subdomain
     * @param  string   $record_type
     * @param  string   $mx_pref      Optional, default ''
     * @return array result
     */
    public function dns_record_del_by_value($domain, $subdomain, $record_type, $mx_pref = '')
    {
        $data['command'] = 'dns_record_del';

        [$data['domein'], $data['tld']] = $this->domain_split($domain);

        $subdomain = $this->_fix_subdomain($domain, $subdomain);

        $display = trim("$subdomain.$domain $record_type $mx_pref");

        $record_id = $this->dns_record_get($domain, $subdomain, $record_type, $mx_pref);

        if (is_array($record_id)) {
            $this->result = [];
            $this->result['command'] = $data['command'];
            $this->_add_error("Error: multiple records found! $display");
            $this->print_result($this->result);
            return $this->result;
        } elseif (!$record_id) {
            // OK: record not found
            $this->result = [];
            $this->result['command']  = $data['command'];
            $this->result['errcount'] = 0;
            $this->result['done']     = 'true';
            return $this->result;
        } else {
            $data['record_id'] = $record_id;
            return $this->do_webservice($data, $display);
        }
    }

    /**
     * Voegt een record toe in de zonefile van een domein
     *
     * @param  string   $domain
     * @param  string   $subdomain
     * @param  string   $record_type
     * @param  string   $destination
     * @param  string   $mx_pref      Optional, default ''
     * @param  string   $weight       Optional, default ''
     * @param  string   $port         Optional, default ''
     * @return array result
     */
    public function dns_record_add($domain, $subdomain, $record_type, $destination, $mx_pref = '', $weight = '', $port = '')
    {
        $data['command'] = __FUNCTION__;
        $data = array_merge($data, $this->_dns_record_add_modify_data($domain, $subdomain, $record_type, $destination, $mx_pref, $weight, $port));

        return $this->do_webservice($data, $data['host'].'.'.$domain.' '.$record_type.rtrim(' '.$mx_pref).rtrim(' '.$weight).rtrim(' '.$port).' '.$destination);
    }

    /**
     * Wijzigt een record in de zonefile van een domein
     *
     * @param  string   $domain
     * @param  integer  $record_id
     * @param  string   $subdomain
     * @param  string   $record_type
     * @param  string   $destination
     * @param  string   $mx_pref      Optional, default ''
     * @param  string   $weight       Optional, default ''
     * @param  string   $port         Optional, default ''
     * @return array result
     */
    public function dns_record_modify($domain, $record_id, $subdomain, $record_type, $destination, $mx_pref = '', $weight = '', $port = '')
    {
        $data['command'] = __FUNCTION__;
        $data['record_id'] = $record_id;
        $data = array_merge($data, $this->_dns_record_add_modify_data($domain, $subdomain, $record_type, $destination, $mx_pref, $weight, $port));

        return $this->do_webservice($data, $data['host'].'.'.$domain.' '.$record_type.rtrim(' '.$mx_pref).rtrim(' '.$weight).rtrim(' '.$port).' '.$destination);
    }

    /**
     * Toevoegen of wijzigen van record in de zonefile op basis van subdomain, record_type en optioneel mx_pref
     *
     * @param  string   $domain
     * @param  string   $subdomain
     * @param  string   $record_type
     * @param  string   $mx_pref      Optional, default ''
     * @return array result
     */
    public function dns_record_add_or_modify($domain, $subdomain, $record_type, $destination, $mx_pref = '')
    {
        $subdomain = $this->_fix_subdomain($domain, $subdomain);
        $record_id = $this->dns_record_get($domain, $subdomain, $record_type, $mx_pref);
        if (is_array($record_id)) {
            $this->result = [];
            $this->result['command'] = 'dns_record_add_or_modify';
            $this->_add_error("Error: multiple records found! $subdomain.$domain $record_type $mx_pref");
            $this->print_result($this->result);
            return $this->result;
        } elseif ($record_id) {
            return $this->dns_record_modify($domain, $record_id, $subdomain, $record_type, $destination, $mx_pref);
        } else {
            return $this->dns_record_add($domain, $subdomain, $record_type, $destination, $mx_pref);
        }
    }

    /**
     * Geeft de SPF-record waarde
     *
     * @param  string   $domain
     * @return string
     */
    public function dns_get_spf($domain)
    {
        $result = '';
        $record_id = $this->dns_record_get($domain, '@', 'TXT');
        if (is_array($record_id)) {
            // multiple SPF-records found
        } elseif ($record_id) {
            $zone_record = $this->dns_record_get_by_id($domain, $record_id);
            if ($zone_record && strpos($zone_record['destination'], 'v=spf1') === 0) {
                $result = $zone_record['destination'];
            }
        } else {
            // no SPF-record found
        }

        return $result;
    }

    /**
     * Toevoegen of wijzigen van SPF-record
     *
     * @param  string   $domain
     * @param  string   $spf_include
     * @return array result
     */
    public function dns_set_spf_include($domain, $spf_include)
    {
        $subdomain = '@';
        $record_type = 'TXT';
        $record_id = $this->dns_record_get($domain, $subdomain, $record_type);
        if (is_array($record_id)) {
            // multiple spf-records found
            $this->result = [];
            $this->result['command'] = 'dns_set_spf_include';
            $this->_add_error("Error: multiple SPF-records found! $subdomain.$domain $record_type [$spf_include]");
            $this->print_result($this->result);
            return $this->result;
        } elseif ($record_id) {
            // change spf-record
            $zone_record = $this->dns_record_get_by_id($domain, $record_id);
            if (!$zone_record) {
                $this->result = [];
                $this->result['command'] = 'dns_set_spf_include';
                $this->_add_error("Error: SPF-record with id $record_id not found! $subdomain.$domain $record_type [$spf_include]");
                $this->print_result($this->result);
                return $this->result;
            }
            $destination = $zone_record['destination'];
            if (strpos($destination, $spf_include) !== false) {
                // no change, because the include is already there
            } elseif (strpos($destination, 'v=spf1') === 0) {
                // add include before last word
                $words = explode(' ', $destination);
                $last_word = array_pop($words);
                $words[] = 'include:'.$spf_include;
                $words[] = $last_word;
                $destination = implode(' ', $words);
            } else {
                $this->result = [];
                $this->result['command'] = 'dns_set_spf_include';
                $this->_add_error("Error: SPF-record with id $record_id is not an SPF-record! $subdomain.$domain $record_type $destination [$spf_include]");
                $this->print_result($this->result);
                return $this->result;
            }
            return $this->dns_record_modify($domain, $record_id, $subdomain, $record_type, $destination);
        } else {
            // add spf-record
            $destination = "v=spf1 a mx include:$spf_include ~all";
            return $this->dns_record_add($domain, $subdomain, $record_type, $destination);
        }
    }

    /**
     * Geeft de TTL (time to live) van een domeinnaam welke de nameservers van MijnDomeinReseller gebruikt
     *
     * @param  string   $domain
     * @return integer
     */
    public function dns_ttl_get($domain)
    {
        $data['command'] = 'dns_get_details';
        [$data['domein'], $data['tld']] = $this->domain_split($domain);
        $result = $this->do_webservice($data);

        return !$this->has_error($result) ? (int) $result['ttl'] : -1;
    }

    /**
     * TTL (time to live) wijzigen van een domeinnaam welke de nameservers van MijnDomeinReseller gebruikt
     * Een zonefile refresh kun je uitvoeren door deze functie uit te voeren.
     * Ook al geef je dezelfde TTL op dan zal de zone alsnog gefresht worden.
     *
     * @param  string   $domain
     * @param  integer  $ttl     Optional, default 900 seconden (15 minuten)
     * @return array result
     */
    public function dns_ttl_modify($domain, $ttl = 900)
    {
        $data['command'] = __FUNCTION__;

        [$data['domein'], $data['tld']] = $this->domain_split($domain);

        $data['ttl'] = $ttl;

        return $this->do_webservice($data, "$domain ttl=$ttl");
    }

    /**
     * Wijzigt de website A records in de zonefile van een domein
     *
     * @param  string   $domain
     * @param  string   $destination
     * @return boolean
     */
    public function dns_set_web_records($domain, $destination)
    {
        $result = true;

        $result = $result && !$this->has_error($this->dns_record_del_by_value($domain, 'www', 'A'));
        $result = $result && !$this->has_error($this->dns_record_add_or_modify($domain, '*', 'A', $destination));
        $result = $result && !$this->has_error($this->dns_record_add_or_modify($domain, '@', 'A', $destination));
        $this->dns_ttl_modify($domain);

        return $result;
    }

    /**
     * Verwijder alle MX records in de zonefile uitgezonderd de opgegeven priority lijst
     *
     * @param  string   $domain     Domein
     * @param  array    $keep_pref  Optional, Priority lijst die niet verwijderd moet worden
     * @return boolean
     */
    public function dns_del_mx_records($domain, $keep_pref = [])
    {
        $result = true;

        $zone_records = $this->dns_get_details($domain);
        if ($zone_records === false) {
            $result = false;
        } else {
            foreach ($zone_records as $row) {
                if ($row['subdomain'] == '@' && $row['record_type'] == 'MX' && !in_array($row['mx_pref'], $keep_pref)) {
                    $result = $result && !$this->has_error($this->dns_record_del($domain, $row['record_id']));
                }
            }
        }
        $this->dns_ttl_modify($domain);

        return $result;
    }

    /**
     * Wijzigt de MX record in de zonefile van een domein naar Google
     *
     * @param  string   $domain
     * @return boolean
     */
    public function dns_set_mx_records_google($domain)
    {
        $result = true;
        $result = $result && $this->dns_del_mx_records($domain, [1]);
        $result = $result && !$this->has_error($this->dns_record_del_by_value($domain, 'mail', 'A'));
        $result = $result && !$this->has_error($this->dns_record_add_or_modify($domain, '@', 'MX', 'ASPMX.L.GOOGLE.COM', 1));
        $result = $result && !$this->has_error($this->dns_record_add($domain, '@', 'MX', 'ALT1.ASPMX.L.GOOGLE.COM', 5));
        $result = $result && !$this->has_error($this->dns_record_add($domain, '@', 'MX', 'ALT2.ASPMX.L.GOOGLE.COM', 5));
        $result = $result && !$this->has_error($this->dns_record_add($domain, '@', 'MX', 'ALT3.ASPMX.L.GOOGLE.COM', 10));
        $result = $result && !$this->has_error($this->dns_record_add($domain, '@', 'MX', 'ALT4.ASPMX.L.GOOGLE.COM', 10));

        // one time mx-verification.google record: @ MX15 abcd123secret456tbd7890.mx-verification.google.com

        $this->dns_ttl_modify($domain);

        return $result;
    }

    /**
     * Verzend de authorizatiekey voor de verhuizing of trade van een .BE domeinnaam naar de houder of genereer een .EU authorizatiekey
     * 1. Een authorizatiekey voor .BE domeinnaam blijft na verzending zeven dagen geldig.
     * 2. Een authorizatiekey voor .EU domeinnaam blijft 40 dagen geldig.
     *
     * @param  string   $domain
     * @return string
     */
    public function domain_auth_info($domain)
    {
        $data['command'] = __FUNCTION__;

        [$data['domein'], $data['tld']] = $this->domain_split($domain);

        return $this->do_webservice($data, $domain);
    }

    /**
     * Opheffen .nl domeinnaam
     *
     * @param  string   $domain
     * @return array result
     */
    public function domain_delete($domain)
    {
        $data['command'] = __FUNCTION__;

        [$data['domein'], $data['tld']] = $this->domain_split($domain);

        return $this->do_webservice($data, $domain);
    }

    /**
     * Toon de details van een domeinnaam
     *
     * @param  string   $domain
     * @return array result
     */
    public function domain_get_details($domain)
    {
        $data['command'] = __FUNCTION__;

        [$data['domein'], $data['tld']] = $this->domain_split($domain);

        return $this->do_webservice($data, $domain);
    }

    /**
     * Get domain authkey
     *
     * @param  string   $domain
     * @return string
     */
    public function domain_authkey($domain)
    {
        $details = $this->domain_get_details($domain);

        return $details['authkey'];
    }

    /**
     * Toon een overzicht van de domeinnamen uit het account
     *
     * @param  string   $tld   Optional, Default ''
     * @param  string   $sort  Optional, Default ''. domein/registrant/admin/tech/verloopdatum/status
     * @param  integer  $order Optional, Default 0=ASC. 1=DESC
     * @param  integer  $begin Optional, Default ''. Toon domeinnamen beginnend met letter van alfabet, waarden: a,b,c….x,y,z of 0-9
     * @return [domein, registrant, admin, tech, verloopdatum, status, autorenew] or false
     */
    public function domain_list($tld = '', $sort = '', $order = 0, $begin = '')
    {
        $data['command'] = __FUNCTION__;

        $data['tld']   = $tld;
        $data['sort']  = $sort;
        $data['order'] = $order;
        $data['begin'] = $begin;

        $result = $this->do_webservice($data);

        if ($this->has_error($result)) {
            $r = false;
        } else {
            $r = [];
            $total = (int) $result['domeincount'];
            for ($i = 0; $i < $total; $i++) {
                $r[] = [
                    'domein'       => $result["domein[$i]"],        // Domeinnaam
                    'registrant'   => $result["registrant[$i]"],    // Naam van registrant
                    'admin'        => $result["admin[$i]"],         // Naam van admin. contact
                    'tech'         => $result["tech[$i]"],          // Naam van tech. contact
                    'verloopdatum' => $result["verloopdatum[$i]"],  // Verloopdatum van domeinnaam in formaat: dd-mm-jjjj
                    'status'       => $result["status[$i]"],        // Status van domeinnaam
                    'autorenew'    => $result["autorenew[$i]"],     // Autorenew waarde, waarden: 1 voor aan of 0 voor uit
                ];
            }
        } // endif

        return $r;
    }

    /**
     * Toon een overzicht van de domeinnamen welke in de afgelopen 30 dagen uit het account zijn wegverhuisd
     *
     * @return [domein, tld, datum] or false
     */
    public function domain_list_delete()
    {
        $data['command'] = __FUNCTION__;

        $result = $this->do_webservice($data);

        if ($this->has_error($result)) {
            $r = false;
        } else {
            $r = [];
            $total = (int) $result['domain_list_delete_count'];
            for ($i = 0; $i < $total; $i++) {
                $r[] = [
                    'domein' => $result["domein[$i]"],  // Domeinnaam
                    'tld'    => $result["tld[$i]"],     // TLD van domeinnaam
                    'datum'  => $result["datum[$i]"],   // Datum van wegverhuizing
                    // 'type'   => $result["type[$i]"],    // Type verwijdering, toont enkel "verhuizing"
                ];
            }
        } // endif

        return $r;
    }

    /**
     * Wijzig de contacten van een domeinnaam
     *
     * @param  string   $domain
     * @param  string   $registrant_id
     * @param  string   $admin_id
     * @param  string   $tech_id
     * @param  string   $bill_id
     * @return array result
     */
    public function domain_modify_contacts($domain, $registrant_id, $admin_id, $tech_id, $bill_id)
    {
        $data['command'] = __FUNCTION__;

        [$data['domein'], $data['tld']] = $this->domain_split($domain);

        if (in_array($data['tld'], ['nl', 'be', 'eu'])) {
            $data['command'] = 'domain_trade';
        }

        $data['registrant_id'] = $registrant_id;
        $data['admin_id']      = $admin_id;
        $data['tech_id']       = $tech_id;
        $data['bill_id']       = $bill_id;

        return $this->do_webservice($data, "$domain registrant_id=$registrant_id admin_id=$admin_id tech_id=$tech_id bill_id=$bill_id");
    }

    /**
     * Wijzig de nameservers van een domeinnaam
     *
     * @param  string   $domain
     * @param  boolean  $gebruik_dns   Optional, Gebruik de nameservers van MijnDomeinReseller, waarden: true/false
     * @param  string   $ns_id         Optional, NameserverID van nameserverset
     * @param  string   $dns_template  Optional, Naam van DNS template
     * @return array result
     */
    public function domain_modify_ns($domain, $gebruik_dns = false, $ns_id = '', $dns_template = '')
    {
        $data['command'] = __FUNCTION__;

        [$data['domein'], $data['tld']] = $this->domain_split($domain);

        $gebruik_dns = $gebruik_dns ? 'true' : 'false';
        $data['gebruik_dns']  = $gebruik_dns;
        $data['ns_id']        = $ns_id;
        $data['dns_template'] = $dns_template;

        return $this->do_webservice($data, $domain.' gebruik_dns='.$gebruik_dns.($ns_id ? ' ns_id='.$ns_id : '').($dns_template ? ' dns_template='.$dns_template : ''));
    }

    /**
     * Verhuizen van een bestaande domeinnaam welke in een ander account bij MijnDomeinReseller is ondergebracht (interne verhuizing)
     *
     * @param  string   $domain   Domeinnaam voor verhuizing
     * @param  string   $authkey  Authorizatiekey voor verhuizing domeinnaam
     * @return array result
     */
    public function domain_push_request($domain, $authkey)
    {
        $data['command'] = __FUNCTION__;

        [$data['domein'], $data['tld']] = $this->domain_split($domain);

        $data['authkey'] = $authkey;

        return $this->do_webservice($data, $domain);
    }

    /**
     * Registratie van een nieuwe domeinnaam
     *
     * @param  string   $domain
     * @param  boolean  $gebruik_dns    Gebruik de nameservers van MijnDomeinReseller
     * @param  string   $dns_template   Naam van DNS template
     * @param  string   $registrant_id
     * @param  string   $admin_id
     * @param  string   $tech_id
     * @param  string   $bill_id
     * @return array result
     */
    public function domain_register($domain, $gebruik_dns, $dns_template, $registrant_id, $admin_id, $tech_id, $bill_id)
    {
        $data['command'] = __FUNCTION__;

        [$data['domein'], $data['tld']] = $this->domain_split($domain);

        $data['gebruik_dns']   = $gebruik_dns ? 'true' : 'false';
        $data['dns_template']  = $dns_template;
        $data['registrant_id'] = $registrant_id;
        $data['admin_id']      = $admin_id;
        $data['tech_id']       = $tech_id;
        $data['bill_id']       = $bill_id;

        $data['lock']      = in_array($data['tld'], ['com', 'net']) ? 'true' : 'false'; // Domeinlock
        $data['autorenew'] = 'true'; // Autorenew inschakelen, waarden true/false
        $data['duur']      = 1; // Duur van registratie in jaar, waarden: 1 t/m 5

        $info = ' gebruik_dns='.$gebruik_dns.($dns_template ? ' dns_template='.$dns_template : '');

        return $this->do_webservice($data, $domain.$info);
    }

    /**
     * Verleng een domeinnaam handmatig
     *
     * @param  string   $domain  Domeinnaam
     * @param  integer  $duur    Numerieke waarde voor aantal te verlengen jaren
     * @return array result
     */
    public function domain_renew($domain, $duur)
    {
        $data['command'] = __FUNCTION__;

        [$data['domein'], $data['tld']] = $this->domain_split($domain);

        $data['duur'] = $duur;

        return $this->do_webservice($data, $domain);
    }

    /**
     * Heractiveer een domeinnaam uit quarantaine
     * Beschikbaar voor .NL, .BE en .EU domeinnamen
     *
     * @param  string   $domain Domeinnaam
     * @return array result
     */
    public function domain_restore($domain)
    {
        $data['command'] = __FUNCTION__;

        [$data['domein'], $data['tld']] = $this->domain_split($domain);

        return $this->do_webservice($data, $domain);
    }

    /**
     * Schakel de autorenew in of uit
     *
     * @param  string   $domain
     * @param  boolean  $autorenew           Autorenew instellen, waarden: true (inschakelen) / false (uitschakelen)
     * @param  string   $registrant_approve  Optional, Toestemming van registrant voor opheffen domeinnaam (enkel bij .DE extensie)
     *                                       waarden: true (toestemming) / false (geen toestemming)
     *                                       Wanneer een .DE domeinnaam zonder toestemming van de houder wordt opgeheven
     *                                       dan wordt de domeinnaam naar de Denic transit gepusht.
     *                                       Zie voor meer informatie: https://transit.secure.denic.de/en/
     * @return array result
     */
    public function domain_set_autorenew($domain, $autorenew, $registrant_approve = true)
    {
        $data['command'] = __FUNCTION__;

        [$data['domein'], $data['tld']] = $this->domain_split($domain);

        $autorenew = $autorenew ? 'true' : 'false';
        $data['autorenew'] = $autorenew;

        if (!$autorenew && $data['tld'] == 'de') {
            $registrant_approve = $registrant_approve ? 'true' : 'false';
            $data['registrant_approve'] = 'true';
        } else {
            $registrant_approve = '';
        }

        return $this->do_webservice(
            $data,
            $domain.
            ' autorenew='.$autorenew.
            (!empty($registrant_approve) ? ' registrant_approve='.$registrant_approve : '')
        );
    }

    /**
     * Stel een domeinlock in
     *
     * @param  string   $domain  Domeinnaam
     * @param  boolean  $lock    Domeinlock instellen
     * @return array result
     */
    public function domain_set_lock($domain, $lock)
    {
        $data['command'] = __FUNCTION__;

        [$data['domein'], $data['tld']] = $this->domain_split($domain);

        $data['lock'] = $lock ? 'true' : 'false';

        return $this->do_webservice($data, $domain);
    }

    /**
     * Verhuizen van een bestaande domeinnaam
     *
     * @param  string   $domain
     * @param  string   $authkey
     * @param  boolean  $gebruik_dns
     * @param  string   $dns_template
     * @param  string   $registrant_id
     * @param  string   $admin_id
     * @param  string   $tech_id
     * @param  string   $bill_id
     * @return array result
     */
    public function domain_transfer($domain, $authkey, $gebruik_dns, $dns_template, $registrant_id, $admin_id, $tech_id, $bill_id)
    {
        $data['command'] = __FUNCTION__;

        [$data['domein'], $data['tld']] = $this->domain_split($domain);

        $data['authkey']       = $authkey;

        $data['gebruik_dns']   = $gebruik_dns ? 'true' : 'false';
        $data['dns_template']  = $dns_template;
        $data['registrant_id'] = $registrant_id;
        $data['admin_id']      = $admin_id;
        $data['tech_id']       = $tech_id;
        $data['bill_id']       = $bill_id;

        $data['lock']      = in_array($data['tld'], ['com', 'net']) ? 'true' : 'false'; // Domeinlock
        $data['autorenew'] = 'true'; // Autorenew inschakelen, waarden true/false
        $data['duur']      = 1; // Duur van registratie in jaar, waarden: 1 t/m 5

        $info = ' gebruik_dns='.$gebruik_dns.($dns_template ? ' dns_template='.$dns_template : '');

        return $this->do_webservice($data, $domain.$info);
    }

    /**
     * Creëer een nieuwe nameserverset
     *
     * @param  string   $ns1
     * @param  string   $ns2
     * @param  string   $ns3
     * @param  string   $ns1_ip
     * @param  string   $ns2_ip
     * @param  string   $ns3_ip
     * @return array result
     */
    public function nameserver_add($ns1, $ns2, $ns3 = '', $ns1_ip = '', $ns2_ip = '', $ns3_ip = '')
    {
        $data['command'] = __FUNCTION__;

        $auto = true;
        $auto = $auto ? 'true' : 'false';

        $data['auto']   = $auto;
        $data['ns1']    = $ns1;
        $data['ns2']    = $ns2;
        $data['ns3']    = $ns3;
        $data['ns1_ip'] = $ns1_ip;
        $data['ns2_ip'] = $ns2_ip;
        $data['ns3_ip'] = $ns3_ip;

        return $this->do_webservice($data, "auto=$auto ns1=$ns1 ns2=$ns2 ns3=$ns3 ns1_ip=$ns1_ip ns2_ip=$ns2_ip ns3_ip=$ns3_ip");
    }

    /**
     * Toon een overzicht van de nameserversets uit het account
     *
     * @return array result
     */
    public function nameserver_list()
    {
        $data['command'] = __FUNCTION__;

        $result = $this->do_webservice($data);

        $r = [];
        if ($this->has_error($result)) {
            $r[] = ['ns_id' => $this->get_error($result)];
        } else {
            $total = (int) $result['nscount'];
            for ($i = 0; $i < $total; $i++) {
                $r[] = [
                    'ns_id'  => $result["ns_id[$i]"],
                    'ns_ns1' => $result["ns_ns1[$i]"],
                    'ns_ns2' => $result["ns_ns2[$i]"],
                    'ns_ns3' => $result["ns_ns3[$i]"],
                ];
            }
        }

        return $r;
    }

    /**
     * DNS lookup utility
     *
     * @param  string   $domain
     * @return array    [$dig_output, $zonefile]
     */
    public function dig($domain)
    {
        $ns = 'ns1.mijndnsserver.nl';

        $zone_records = $this->dns_get_details($domain);
        if ($zone_records === false) {
            $zonefile = $this->get_error();
            $dig_output = '';
        } else {
            $subdomains = [];
            $records    = [];
            foreach ($zone_records as $row) {
                $subdomain    = $this->_fix_subdomain($domain, $row['subdomain']);
                $record_type  = $row['record_type'];
                $mx_pref      = $row['mx_pref'];
                $destination  = $row['destination'];
                $subdomains[] = $subdomain;
                if (in_array($row['record_type'], ['A', 'CNAME', 'MX'])) {
                    $records[] = $record_type.(strlen($mx_pref) ? ' '.lz($mx_pref, 3) : '').' '.$subdomain.' '.strtolower($destination)."\n";
                }
            }
            sort($records);

            $zonefile = implode('', $records);
            $zonefile = str_replace([' 00', ' 0'], ' ', $zonefile); // remove leading zero's
            $zonefile = preg_replace('/ +/', ' ', $zonefile); // remove multiple spaces

            foreach (array_unique($subdomains) as $subdomain) {
                $query = $subdomain == '@' ? $domain : $subdomain.'.'.$domain;
                if ($this->debug) {
                    echo "dig @$ns +noall +answer -t A     -q $query\n";
                    echo "dig @$ns +noall +answer -t CNAME -q $query\n";
                    echo "dig @$ns +noall +answer -t MX    -q $query\n";
                }
                $dig_output .= `dig @$ns +noall +answer -t A     -q $query`.
                               `dig @$ns +noall +answer -t CNAME -q $query`.
                               `dig @$ns +noall +answer -t MX    -q $query`;
            }

            $dig_output = $this->_fix_dig_output($domain, $dig_output);
        }

        return [$dig_output, $zonefile];
    }

    /**
     * Fix dig output
     *
     * @param  string   $domain
     * @param  string   $input
     * @return string
     */
    private function _fix_dig_output($domain, $input)
    {
        $output = [];
        $input = str_replace("\t", ' ', $input);    // replace tabs with spaces
        $input = preg_replace('/ +/', ' ', $input); // remove multiple spaces
        $lines = explode("\n", $input);
        foreach ($lines as $line) {
            $line = trim($line);
            if (strlen($line)) {
                $parts = explode(' ', $line, 6);
                if (count($parts) == 5) {
                    [$subdomain, $ttl, $class, $record_type, $destination] = $parts;
                    $mx_pref = '';
                } else {
                    [$subdomain, $ttl, $class, $record_type, $mx_pref, $destination] = $parts;
                }
                $subdomain = $this->_fix_subdomain($domain, $subdomain);

                // remove destination trailing dot:
                if (substr($destination, -1) == '.') {
                    $destination = substr($destination, 0, -1);
                }

                $output[] = $record_type.(strlen($mx_pref) ? ' '.lz($mx_pref, 3) : '').' '.$subdomain.' '.strtolower($destination)."\n";
            }
        }
        sort($output);

        $output = array_unique($output);
        $output = implode('', $output);
        $output = str_replace([' 00', ' 0'], ' ', $output); // remove leading zero's
        $output = preg_replace('/ +/', ' ', $output); // remove multiple spaces

        return $output;
    }

    /**
     * Toon een overzicht van alle nieuwe gTLD's
     *
     * @return [tld, sunrise_start, sunrise_end, landrush, golive, is_live]
     */
    public function newgtld_list()
    {
        $data['command'] = __FUNCTION__;
        $result = $this->do_webservice($data);
        $list = [];
        if (!$this->has_error($result)) {
            for ($i = 0, $total = (int) $result['newgtldcount']; $i < $total; $i++) {
                $list = [
                    'tld'           => $result["tld[$i]"],            // Extensie
                    'sunrise_start' => $result["sunrise_start[$i]"],  // Startdatum van sunrise, leeg bij onbekend
                    'sunrise_end'   => $result["sunrise_end[$i]"],    // Einddatum van sunrise, leeg bij onbekend
                    'landrush'      => $result["landrush[$i]"],       // Startdatum van landrush, leeg bij onbekend
                    'golive'        => $result["golive[$i]"],         // Startdatum van golive, leeg bij onbekend
                    'is_live'       => $result["is_live[$i]"],        // Numerieke waarde voor status, 1=actief, 0=nog niet actief
                ];
            }
        }

        return $list;
    }

    /**
     * Toon een overzicht van beschikbare TLD's (extensies)
     *
     * @return array
     */
    public function tld_list()
    {
        $data['command'] = __FUNCTION__;
        $result = $this->do_webservice($data);
        $list = [];
        if (!$this->has_error($result)) {
            for ($i = 0, $total = (int) $result['tldcount']; $i < $total; $i++) {
                $list[] = $result["tld[$i]"];
            }
        }

        return $list;
    }

    /**
     * Toon de details van een beschikbare TLD (extensie)
     *
     * @param  string   $tld
     * @return array result
     */
    public function tld_get_details($tld)
    {
        $data['command'] = __FUNCTION__;

        $data['tld'] = $tld;

        // prijs_registratie       Kosten voor een registratie per jaar (excl BTW)
        // prijs_registratie_munt  Munteenheid van prijs voor registratie
        // prijs_verhuizing        Kosten voor een verhuizing per jaar (excl BTW)
        // prijs_verhuizing_munt   Munteenheid van prijs voor verhuizing
        // prijs_verlenging        Kosten voor de verlenging per jaar (excl BTW)
        // prijs_verlenging_munt   Munteenheid van prijs voor verlenging
        // munt_wisselkoers        Koers van munteenheid t.o.v. euro (alleen wanneer munteenheid anders is dan EUR)
        // lengte_min              Minimale lengte domeinnaam
        // lengte_max              Maximale lengte domeinnaam
        // jaar_min                Minimale registratieduur
        // jaar_max                Maximale registratieduur
        // registreren             TLD beschikbaar voor registratie, 1 voor ja, 0 voor nee
        // verhuizen               TLD beschikbaar voor verhuizing, 1 voor ja, 0 voor nee

        return $this->do_webservice($data, $tld);
    }

    /**
     * Get price in cents
     *
     * @param  array    $details  Details van een beschikbare TLD (extensie)
     * @param  string   $key
     * @return integer  Price in cents
     */
    private function _tld_price(array $details, string $key): int
    {
        $price = (int) str_replace('.', '', sanitize_str($details, "prijs_$key", '0'));

        $rate = sanitize_str($details, 'munt_wisselkoers', 0);
        if ($rate) {
            $currency = sanitize_str($details, "prijs_${key}_munt", 'EUR');
            if ($currency && $currency != 'EUR') {
                $price = (int) round($price * $rate);
            }
        }

        return $price;
    }

    /**
     * Purchase price in cents
     *
     * @param  array    $details  Details van een beschikbare TLD (extensie)
     * @return integer  Price in cents
     */
    public function purchase_price(array $details): int
    {
        return $this->_tld_price($details, 'registratie');
    }

    /**
     * Transfer price in cents
     *
     * @param  array    $details  Details van een beschikbare TLD (extensie)
     * @return integer  Price in cents
     */
    public function transfer_price(array $details): int
    {
        return $this->_tld_price($details, 'verhuizing');
    }

    /**
     * Renew price in cents
     *
     * @param  array    $details  Details van een beschikbare TLD (extensie)
     * @return integer  Price in cents
     */
    public function renew_price(array $details): int
    {
        return $this->_tld_price($details, 'verlenging');
    }

} // end class
