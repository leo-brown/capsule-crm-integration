<?php
/**
  * CapsuleCRM API Helper
  * @author Netfuse Integrations Team
  *  Sample usage:
  *   $capsule = new CapsuleAPI('example.capsulecrm.com','b016ce5d33b56bafd616955e');
  *   $parties = $capsule->findParty('gerald');
  *   if($parties) foreach($parties as $party){
  *     $api->addNote($party->id, 'Found you');
  *   }
  */

// Configure the local timezone to match your Synthesis account (typically Europe/London)
date_default_timezone_set('Europe/London');

/**
  * CapsuleAPI Class - provides basic functionality via Capsule for searching/adding contacts and notes
  * @author Netfuse Integrations Team
  */
Class CapsuleAPI {

	/** Internal class Vars **/
	private $host = '';
	private $key;
	private $protocol = 'https';

	/**
	  * Constructor for CapsuleCRM API Helper
	  * @param String $host Hostname for Capsule CRM
	  * @param String $key Authentication key for Capsule API
	  * @return Void
	  */
	function CapsuleAPI($host, $key) {
		$this->host = $host;
		$this->key = $key;
	}

	/**
	  * Dispatches an action to Capsule as JSON and returns the result
	  * @param String $endpoint URL to dispatch to
	  * @param String $very Verb to use for request (default GET)
	  * @param String $payload Data to send
	  * @return Boolean false on failure, otherwise array of results
	  * @todo Check that param and return types match
	  */
	function action($endpoint, $verb = 'GET', $payload=false) {
		// Instantiate and configure CURL
		global $curl;
		if(!$curl) {
			$curl = curl_init(); 
			curl_setopt_array($curl, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true, 
				CURLOPT_USERPWD        => $this->key.':x',
				CURLOPT_HTTPHEADER     => array(
					"Content-Type: application/json",
					"Accept: application/json"
				)
			));
		}

		// Handle POSTs to the API
		if($payload){
			curl_setopt($curl, CURLOPT_POST, 1);
      		curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
		}

		// Handle verb
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $verb);
		
		// Go
		curl_setopt($curl,CURLOPT_URL, $endpoint);
		if(!$output = curl_exec($curl)){
			// error is curl_erro($curl)
			return false;
		}
		if(!$output = json_decode($output, true)){
			return false;
		}
		// We should have some nice data; return it
		return $output;
	}

	/**
	  * Finds a party based on a search term
	  * @param String search term to find party by
	  * @return Mixed False on failure, otherwise an array of results
	  */
	function findParty($search = '') {
		$result = $this->action(
			$this->protocol.'://'.$this->host.'/api/'."party/?q=".$search
		);
		if($result['parties']['@size'] == '0') return false;
		else {
			return $result['parties'];
		}
	}

	/**
	  * Returns data on a specified partyID.
	  * @param String UID of the party.
	  * @return Mixed False on failure, otherwise an array of results
	  */
	function findPartyById($partyID) {
		$result = $this->action(
			$this->protocol.'://'.$this->host.'/api/party/'.$partyID
		);
		return $result;
	}

	/**
	  * Finds a user based on a search term
	  * @param String search term to find user by
	  * @return Mixed False on failure, otherwise an array of results
	  */
	function getUsers() {
		
		$result = $this->action(
			$this->protocol. '://'.
			$this->host.'/api/'.
			"user?q="
		);
		if(!$result['users']['@size']) return false;
		else return $result['users']['user'];
	}
	
	/**
	  * Get notes for a given party ID
	  * @param Integer @partyID 
	  * @return Mixed False for a failed request, otherwise an array of results
	  * @todo Ensure return type matches
	  */
	function getNotes($partyID) {
		$result = $this->action(
			$this->protocol.'://'.
			$this->host.'/api/'.
			'party/'.$partyID.'/history'
		);

		// Need history object to work with
		if(!isset($result['history']))    return false;
		if(!@$result['history']['@size']) return false;
		return $result;
	}

	/**
	  * Adds a note to a party
	  * @param Integer @partyID
	  * @param DateTime date to add note in relation to
	  * @param Array Array of custom fields to add
	  * @return Mixed False for a failed request, otherwise an array of results
	  * @todo Make param and return types match
	  */
	function addNote($partyID, $payload) {
		$payload = json_encode($payload, JSON_FORCE_OBJECT);
		$request = $this->action(
			$this->protocol.'://'.$this->host.'/api/'.'party/'.$partyID.'/history',
			'POST',$payload
		);
		return $request;
	}	
}