<?php

class QuickBase {

    /*---------------------------------------------------------------------
    // User Configurable Options
    -----------------------------------------------------------------------*/

    public $username = ''; // QuickBase user who will access the QuickBase
    public $password = ''; // Password of this user
    public $timeout = '90'; // timeout for request (in seconds);
    var $db_id = ''; // Table/Database ID of the QuickBase being accessed
    var $app_token = '';
    var $xml = true;
    var $user_id = 0;
    var $qb_site = "www.quickbase.com";
    var $qb_ssl = "https://www.quickbase.com/db/";
    var $ticketHours = '';

    /*---------------------------------------------------------------------
    // Do Not Change
    -----------------------------------------------------------------------*/

    var $input = '';
    var $ticket = '';

    /* --------------------------------------------------------------------*/

    public function __construct($un, $pw, $usexml = true, $db = '', $token = '', $realm = '', $hours = '') {

        if ($un) {
            $this->username = $un;
        }

        if ($pw) {
            $this->password = $pw;
        }

        if ($db) {
            $this->db_id = $db;
        }

        if ($token)
            $this->app_token = $token;

        if ($realm) {

            if (strpos($realm, '.') > 0)
                $this->qb_site = $realm;
            else
                $this->qb_site = $realm . '.quickbase.com';

            $this->qb_ssl = 'https://' . $this->qb_site . '/db/';

        }

        if ($hours) {
            $this->ticketHours = (int)$hours;
        }

        $this->xml = $usexml;

        $this->authenticate();
    }

    public function set_xml_mode($bool) {
        $this->xml = $bool;
    }

    public function set_database_table($db) {
        $this->db_id = $db;
    }

    private function transmit($input, $action_name = "", $url = "", $return_xml = true) {

        if (empty($url)) {
            $url = $this->qb_ssl . $this->db_id;
        }

        $content_length = strlen($input);

        $headers = array(
            "POST /db/" . $this->db_id . " HTTP/1.0",
            "Content-Type: text/xml;",
            "Accept: text/xml",
            "Cache-Control: no-cache",
            "Pragma: no-cache",
            "Content-Length: " . $content_length,
            'QUICKBASE-ACTION: ' . $action_name
        ); //var_dump($headers); echo $url;

        $this->input = $input; //echo '<pre>'; var_dump($this->input); echo '</pre>';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $input);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        // Set timeout
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $r = curl_exec($ch); //echo '<pre>'; var_dump($r); echo '</pre>';

        if ($return_xml) {
            if (!$r) {
                if ($this->xml instanceof SimpleXMLElement)
                    throw new Exception('QuickBase: Authentication Failed against realm: "' . $this->qb_site . '" using user "' . $this->username . '" ' . "\n" . $this->xml->asXML());
                else
                    throw new Exception('QuickBase: Authentication Failed against realm: "' . $this->qb_site . '" using user "' . $this->username . '" ' . "\n" . $this->xml);
            }

            $response = new SimpleXMLElement($r);
        } else {
            $response = $r;
        }

        return $response;
    }

    /* API_Authenticate: https://www.quickbase.com/up/6mztyxu8/g/rc7/en/va/QuickBaseAPI.htm#_Toc126579970 */
    public function authenticate() {
        $xml_packet = new SimpleXMLElement('<qdbapi></qdbapi>');
        $xml_packet->addChild('username', $this->username);
        $xml_packet->addChild('password', $this->password);

        if ($this->ticketHours) {
            $xml_packet->addChild('hours', $this->ticketHours);
        }

        $xml_packet->addChild('ticket', $this->ticket);
        $xml_packet = $xml_packet->asXML();

        $response = $this->transmit($xml_packet, 'API_Authenticate', $this->qb_ssl . "main");
        if ($response) {
            $this->ticket = (string)$response->ticket;
            $this->user_id = (string)$response->userid;
        }
        return $response;
    }

    /* For Use with Support Portal Authentication */
    public function authenticate_ticket($ticket, $realm) {
        $response = null;

        $xml_packet = new SimpleXMLElement('<qdbapi></qdbapi>');
        $xml_packet->addChild('ticket', $ticket);
        $xml_packet = $xml_packet->asXML();

        $realm_url = ($realm ? "https://" . $realm . ".quickbase.com/db/" : $this->qb_ssl);
        $response = $this->transmit($xml_packet, 'API_Authenticate', $realm_url . "main");

        if ($response) {
            return $response->userid;
        }
        return false;
    }

    /* API_AddRecord: https://www.quickbase.com/up/6mztyxu8/g/rc7/en/va/QuickBaseAPI.htm#_Toc126579962 */
    public function add_record($fields, $uploads = array()) {
        $xml_packet = new SimpleXMLElement('<qdbapi></qdbapi>');

        $i = intval(0);
        foreach ($fields as $field) {
            $safe_value = preg_replace('/&(?!\w+;)/', '&amp;', $field['value']);

            $xml_packet->addChild('field', $safe_value);
            $xml_packet->field[$i]->addAttribute('fid', $field['fid']);
            $i++;
        }

        if ($uploads) {
            foreach ($uploads as $upload) {
                $xml_packet->addChild('field', $upload['value']);
                $xml_packet->field[$i]->addAttribute('fid', $upload['fid']);
                $xml_packet->field[$i]->addAttribute('filename', $upload['filename']);
                $i++;
            }
        }

        if ($this->app_token) {
            $xml_packet->addChild('apptoken', $this->app_token);
        }

        $xml_packet->addChild('ticket', $this->ticket);
        $xml_packet = $xml_packet->asXML();

        $response = $this->transmit($xml_packet, 'API_AddRecord');
        if (!$response) {
            throw new Exception("QuickBase: Add_Record Failed. \n Request: \n" . $xml_packet->asXML() . "\n Response: " . $response->asXML());
        } else {
            return $response;
        }
    }

    /* API_ChangeRecordOwner: https://www.quickbase.com/up/6mztyxu8/g/rc7/en/va/QuickBaseAPI.htm#_Toc126579977 */
    public function change_record_owner($new_owner, $rid) {
        $xml_packet = new SimpleXMLElement('<qdbapi></qdbapi>');
        $xml_packet->addChild('rid', $rid);
        $xml_packet->addChild('newowner', $new_owner);

        if ($this->app_token) {
            $xml_packet->addChild('apptoken', $this->app_token);
        }

        $xml_packet->addChild('ticket', $this->ticket);
        $xml_packet = $xml_packet->asXML();

        $response = $this->transmit($xml_packet, 'API_ChangeRecordOwner');
        if ($response) {
            return true;
        }
        return false;
    }

    /* API_DeleteRecord: https://www.quickbase.com/up/6mztyxu8/g/rc7/en/va/QuickBaseAPI.htm#_Toc126579996*/
    public function delete_record($rid) {
        $xml_packet = new SimpleXMLElement('<qdbapi></qdbapi>');
        $xml_packet->addChild('rid', $rid);

        if ($this->app_token) {
            $xml_packet->addChild('apptoken', $this->app_token);
        }

        $xml_packet->addChild('ticket', $this->ticket);
        $xml_packet = $xml_packet->asXML();

        $response = $this->transmit($xml_packet, 'API_DeleteRecord');
        if ($response) {
            return true;
        }
        return false;
    }

    /* API_DoQuery: https://www.quickbase.com/up/6mztyxu8/g/rc7/en/va/QuickBaseAPI.htm#_Toc126579999 */
    public function do_query($queries = array(), $qid = 0, $qname = 0, $clist = 0, $slist = 0, $fmt = 'structured', $options = "") {
        //A query in queries has the following items in this order:
        //field id, evaluator, criteria, and/or
        //The first element will not have an and/or
        $xml_packet = '<qdbapi>';

        $pos = 0;

        if ($queries) {
            $xml_packet .= '<query>';
            foreach ($queries as $query) {
                $criteria = "";
                if ($pos > 0) {
                    $criteria .= $query['ao'];
                }
                $criteria .= "{'" . $query['fid'] . "'."
                    . $query['ev'] . ".'"
                    . $query['cri'] . "'}";

                $xml_packet .= $criteria;
                $pos++;
            }
            $xml_packet .= '</query>';
        } else if ($qid) {
            $xml_packet .= '<qid>' . $qid . '</qid>';
        } else if ($qname) {
            $xml_packet .= '<qname>' . $qname . '</qname>';
        } else {
            return false;
        }

        $xml_packet .= '<fmt>' . $fmt . '</fmt>';
        if ($clist) {
            $xml_packet .= '<clist>' . $clist . '</clist>';
        }

        if ($slist) {
            $xml_packet .= '<slist>' . $slist . '</slist>';
        }

        if ($options) {
            $xml_packet .= '<options>' . $options . '</options>';
        }

        //include record id's
        $xml_packet .= '<includeRids>1</includeRids>';

        if ($this->app_token) {
            $xml_packet .= '<apptoken>' . $this->app_token . '</apptoken>';
        }
        $xml_packet .= '<ticket>' . $this->ticket . '</ticket>';
        $xml_packet .= '</qdbapi>';

        $response = $this->transmit($xml_packet, 'API_DoQuery');
        if ($response) {
            return $response;
        }
        return false;
    }

    public function do_query_count($query, $qid = NULL) {
        $xml_packet = new SimpleXMLElement('<qdbapi></qdbapi>');

        if ($qid) {
            $xml_packet->addChild('qid', $qid);
        } else {
            $xml_packet->addChild('query', $query);
        }

        if ($this->app_token) {
            $xml_packet->addChild('apptoken', $this->app_token);
        }

        $xml_packet->addChild('ticket', $this->ticket);
        $xml_packet = $xml_packet->asXML();

        $response = $this->transmit($xml_packet, 'API_DoQueryCount');
        if ($response) {
            return $response;
        }
        return false;
    }

    /* API_EditRecord: https://www.quickbase.com/up/6mztyxu8/g/rc7/en/va/QuickBaseAPI.htm#_Toc126580003 */
    public function edit_record($rid, $fields, $uploads = array(), $updateid = 0) {
        $xml_packet = new SimpleXMLElement('<qdbapi></qdbapi>');
        $xml_packet->addChild('rid', $rid);

        $i = intval(0);
        foreach ($fields as $field) {
            $safe_value = preg_replace('/&(?!\w+;)/', '&amp;', $field['value']);

            $xml_packet->addChild('field', $safe_value);
            $xml_packet->field[$i]->addAttribute('fid', $field['fid']);
            $i++;
        }

        if ($uploads) {
            foreach ($uploads as $upload) {
                $xml_packet->addChild('field', $upload['value']);
                $xml_packet->field[$i]->addAttribute('fid', $upload['fid']);
                $xml_packet->field[$i]->addAttribute('filename', $upload['filename']);
                $i++;
            }
        }

        if ($this->app_token) {
            $xml_packet->addChild('apptoken', $this->app_token);
        }

        $xml_packet->addChild('ticket', $this->ticket);
        $xml_packet = $xml_packet->asXML();

        $response = $this->transmit($xml_packet, 'API_EditRecord');
        if ($response) {
            return $response;
        }
        return false;
    }

    /* API_GenAddRecordForm: https://www.quickbase.com/up/6mztyxu8/g/rc7/en/va/QuickBaseAPI.htm#_Toc126580019 */
    public function find_db_by_name($db_name) {
        $xml_packet = new SimpleXMLElement('<qdbapi></qdbapi>');
        $xml_packet->addChild('dbname', $db_name);
        $xml_packet->addChild('ticket', $this->ticket);

        $response = $this->transmit($xml_packet, 'API_FindDBByName');
        if ($response) {
            return $response->db_id;
        }
        return false;
    }

    /**
     * API_GetUserInfo
     */
    public function get_user_info($email, $udata = NULL) {
        $xml_packet = new SimpleXMLElement('<qdbapi></qdbapi>');
        $xml_packet->addChild('email', $email);

        if (!isset($udata)) {
            $xml_packet->addChild('udata', $udata);
        }

        if ($this->app_token) {
            $xml_packet->addChild('apptoken', $this->app_token);
        }

        $xml_packet->addChild('ticket', $this->ticket);
        $xml_packet = $xml_packet->asXML();

        $response = $this->transmit($xml_packet, 'API_GetUserInfo', $this->qb_ssl . "main");
        if ($response) {
            return $response;
        }
        return false;
    }

    /* API_GetNumRecords: https://www.quickbase.com/up/6mztyxu8/g/rc7/en/va/QuickBaseAPI.htm#_Toc126580037 */
    public function get_num_records() {
        $xml_packet = new SimpleXMLElement('<qdbapi></qdbapi>');

        if ($this->app_token) {
            $xml_packet->addChild('apptoken', $this->app_token);
        }

        $xml_packet->addChild('ticket', $this->ticket);
        $xml_packet = $xml_packet->asXML();

        $response = $this->transmit($xml_packet, 'API_GetNumRecords');
        if ($response) {
            return $response;
        }
        return false;
    }

    /* API_GetRecordInfo: https://www.quickbase.com/up/6mztyxu8/g/rc7/en/va/QuickBaseAPI.htm#_Toc126580046 */
    public function get_record_info($rid) {
        $xml_packet = new SimpleXMLElement('<qdbapi></qdbapi>');
        $xml_packet->addChild('rid', $rid);

        if ($this->app_token) {
            $xml_packet->addChild('apptoken', $this->app_token);
        }

        $xml_packet->addChild('ticket', $this->ticket);
        $xml_packet = $xml_packet->asXML();

        $response = $this->transmit($xml_packet, 'API_GetRecordInfo');
        if ($response) {
            return $response;
        }
        return false;
    }

    /* API_GetSchema: https://www.quickbase.com/up/6mztyxu8/g/rc7/en/va/QuickBaseAPI.htm#_Toc126580049 */
    public function get_schema() {
        $xml_packet = new SimpleXMLElement('<qdbapi></qdbapi>');

        if ($this->app_token) {
            $xml_packet->addChild('apptoken', $this->app_token);
        }

        $xml_packet->addChild('ticket', $this->ticket);
        $xml_packet = $xml_packet->asXML();

        $response = $this->transmit($xml_packet, 'API_GetSchema');
        if ($response) {
            return $response;
        }
        return false;
    }

    /* API_GrantedDB's: https://www.quickbase.com/up/6mztyxu8/g/rc7/en/va/QuickBaseAPI.htm#_Toc126580052 */
    public function granted_dbs($excludeParents = 1, $withEmbeddedTables = 1, $adminOnly = 1) {
        $xml_packet = new SimpleXMLElement('<qdbapi></qdbapi>');
        $xml_packet->addChild('excludeparents', $excludeParents);
        $xml_packet->addChild('withembeddedtables', $withEmbeddedTables);
        $xml_packet->addChild('adminonly', $adminOnly);
        $xml_packet->addChild('ticket', $this->ticket);
        $xml_packet = $xml_packet->asXML();

        $response = $this->transmit($xml_packet, 'API_GrantedDBs', $this->qb_ssl . "main");
        if ($response) {
            return $response;
        }
        return false;
    }

    /*API_ImportFromCSV: https://www.quickbase.com/up/6mztyxu8/g/rc7/en/va/QuickBaseAPI.htm#_Toc126580055 */
    public function import_from_csv($records_csv, $clist, $skip_first = 0) {
        $response = null;
        $xml_packet = new SimpleXMLElement('<qdbapi></qdbapi>');

        $xml_records_csv = $xml_packet->addChild('records_csv');
        $node = dom_import_simplexml($xml_records_csv);
        $node->appendChild($node->ownerDocument->createCDATASection($records_csv));

        $xml_packet->addChild('clist', $clist);
        $xml_packet->addChild('skipfirst', $skip_first);
        $xml_packet->addChild('ticket', $this->ticket);

        if ($this->app_token) {
            $xml_packet->addChild('apptoken', $this->app_token);
        }

        $xml_packet = $xml_packet->asXML();

        $response = $this->transmit($xml_packet, 'API_ImportFromCSV');
        if ($response) {
            return $response;
        }
        return false;
    }

    /* API_PurgeRecords: https://www.quickbase.com/up/6mztyxu8/g/rc7/en/va/QuickBaseAPI.htm#_Toc126580061 */
    public function purge_records($queries = array(), $qid = 0, $qname = 0) {
        $xml_packet = '<qdbapi>';

        $pos = 0;
        if ($queries) {
            $xml_packet .= '<query>';
            foreach ($queries as $query) {
                $criteria = "";
                if ($pos > 0) {
                    $criteria .= $query['ao'];
                }
                $criteria .= "{'" . $query['fid'] . "'."
                    . $query['ev'] . ".'"
                    . $query['cri'] . "'}";

                $xml_packet .= $criteria;
                $pos++;
            }
            $xml_packet .= '</query>';
        } else if ($qid) {
            $xml_packet .= '<qid>' . $qid . '</qid>';
        } else if ($qname) {
            $xml_packet .= '<qname>' . $qname . '</qname>';
        } else {
            return false;
        }

        if ($this->app_token)
            $xml_packet .= '<apptoken>' . $this->app_token . '</apptoken>';

        $xml_packet .= '<ticket>' . $this->ticket . '</ticket></qdbapi>';

        $response = $this->transmit($xml_packet, 'API_PurgeRecords');
        if ($response) {
            return $response;
        }
        return false;
    }

    /* API_SignOut: https://www.quickbase.com/up/6mztyxu8/g/rc7/en/va/QuickBaseAPI.htm#_Toc126580069 */
    public function sign_out() {
        $xml_packet = new SimpleXMLElement('<qdbapi></qdbapi>');

        $response = $this->transmit($xml_packet, 'API_SignOut', $this->qb_ssl . "main");
        if ($response) {
            return true;
        }
        return false;
    }

    /* API_RunImport */
    public function run_import($id) {
        $xml_packet = new SimpleXMLElement('<qdbapi></qdbapi>');
        $xml_packet->addChild('id', $id);

        if ($this->app_token) {
            $xml_packet->addChild('apptoken', $this->app_token);
        }

        $xml_packet->addChild('ticket', $this->ticket);
        $xml_packet = $xml_packet->asXML();

        $response = $this->transmit($xml_packet, 'API_RunImport');
        if ($response) {
            return $response;
        }
        return false;
    }
}
