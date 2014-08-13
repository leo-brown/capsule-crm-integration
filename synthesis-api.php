<?php
/**
  * Synthesis API Object
  * @author Leo Brown
  */
Class SynthesisAPI {

  var $host='';
  var $version='0.1';
  var $key;
  var $secret;
  var $protocol = 'http';

  public $authenticated = false;

  /**
    * Constructor
    *
    */
  function SynthesisAPI($host, $key, $secret){
    // check whether http/https was specified
    if('https' == substr($host,0,5)){
            $this->protocol = 'https';
            $this->host = trim(substr($host,8),'/');
    }
    elseif('http' == substr($host,0,4)){
            $this->protocol = 'http';
            $this->host = trim(substr($host,7),'/');
    }
    else $this->host = $host;

    // Set local vas
    $this->key    = $key;
    $this->secret = $secret;

    // Login
    $this->login();
  }

  /**
    * Login to API
    *
    */
  function login() {
    $result = $this->action(
      $this->protocol. '://'.
      $this->host.'/'.
      $this->version.
      "/authenticate?key={$this->key}&secret={$this->secret}"
    );

    // Return auth status and set local auth status
    return $this->authenticated = @$result['status']['code'] == 200;
  }

  /**
    * Execute a Synthesis API call
    * @param String $endpoint API request like /calls
    * @param String $verb HTTP Verb like GET, POST, DELETE etc
    * @payload Array Payload data for POST verb
    */
  function action($endpoint, $verb='GET', $payload=false, $payload_is_file=false) {
    global $s;
    if(!$s) {
      $s = curl_init();
      curl_setopt($s,CURLOPT_COOKIEJAR, '/tmp/synthesis_api_cookies_'.md5(uniqid()));
      curl_setopt($s,CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($s,CURLOPT_SSL_VERIFYPEER, false);
    }

    // upload file
    if($payload && $payload_is_file) {
      $fp = fopen($payload, 'r');
      curl_setopt($s, CURLOPT_POST, 1);
      curl_setopt($s, CURLOPT_POSTFIELDS, array('file'=>'@'.$payload));
      curl_setopt($s, CURLOPT_UPLOAD, 1);
      curl_setopt($s, CURLOPT_TIMEOUT, 86400);
      curl_setopt($s, CURLOPT_INFILE, $fp);
      curl_setopt($s, CURLOPT_INFILESIZE, filesize($payload));
      // Can't close $fp yet
    }

    // Handle verbage
    curl_setopt($s, CURLOPT_CUSTOMREQUEST, $verb);

    // Execute
    curl_setopt($s,CURLOPT_URL, $endpoint);
    if(!$output = curl_exec($s)) {
      echo curl_error($s);
      return false;
    }
    if(!$output = json_decode($output, true)){
      return false;
    }
    return $output;
  }

  /**
   * Method that consumes the /calls API endpoint. 
   * @param  string $number optional parameter, find calls for a given number.
   * @return mixed          returns an array of results. Returns false if there is no data to return.
   */
  function getCalls($number = null) {
    
    if(!$number) $url = $this->protocol. '://'.$this->host.'/'.$this->version.'/calls';
    else $url = $this->protocol. '://'.$this->host.'/'.$this->version.'/calls/?number='.$number;
    $result = $this->action($url);

    if($result['status']['code'] != 200) return false; 
    else return $result['results'];
  }
}