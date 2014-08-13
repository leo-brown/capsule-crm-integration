#!/usr/bin/php
<?php

/** 
 *	Synthesis Capsule Integration.
 *	@author Domenico Salvia
 *	
 *  Enables calls made on the Synthesis platform to be automatically noted on a given CapsuleCRM distrubution. 
 *  When executing from the commandline this script must be passed the following arguments:
 *
 * 	synthesis_api_host: i.e. 'api.netfuse.net
 * 	synthesis_api_key
 * 	synthesis_api_secret 
 * 	capsule_api_host: i.e subdomain.capsulecrm.com
 * 	capsule_api_key
 * 	capsule_user_id
 */

require 'synthesis-api.php';
require 'capsule-api.php';

$synthesis_api_host = @$argv[1];
$synthesis_api_key = @$argv[2];
$synthesis_api_secret = @$argv[3];

$capsule_api_host = @$argv[4];
$capsule_api_key = @$argv[5];
$capsule_user_id = @$argv[6];

if(!$synthesis_api_host || !$synthesis_api_key || !$synthesis_api_secret || !$capsule_api_host || !$capsule_api_key || !$capsule_user_id) {
	die("Usage: {$argv[0]} [synthesis_host] [synthesis_key] [synthesis_secret] [capsule_api_host] [capsule_api_key] [capsule_user_id]");
}

// Login to Synthesis and Capsule API's
$synthesisApi = new SynthesisAPI($synthesis_api_host, $synthesis_api_key, $synthesis_api_secret);
$capsuleApi = new CapsuleAPI($capsule_api_host, $capsule_api_key, $capsule_user_id);

if(!$synthesisApi->authenticated) {
	die('Error: Authentication with Synthesis failed');
}
	
$user = $capsuleApi->findUser();
$party = $capsuleApi->findPartyById($user['partyId']);

$calls = array();

foreach ($party['person']['contacts']['phone'] as $number) {
	$calls = array_merge($calls, $synthesisApi->getCalls(stripNumber($number['phoneNumber'], true)));
}

// format CDR's to make them easier to work with.
$formattedCalls = formatCalls($calls);

// Take the formatted CDR's and generate a note if required.
foreach ($formattedCalls as $key => &$formattedCall) {
	
	if($formattedCall['duration'] <= '0') continue;

	$fromParty = $capsuleApi->findParty(stripNumber($formattedCall['from_number']));
	$toParty = $capsuleApi->findParty(stripNumber($formattedCall['to_number']));

	$formattedCall['from_party'] = sanitiseParty($fromParty);
	$formattedCall['to_party'] = sanitiseParty($toParty);
	
	// If no parties are found, theres nothing to do. continue.
	if(!array_key_exists('from_party', $formattedCall) && !array_key_exists('to_party', $formattedCall)) {
		unset($formattedCalls[$key]);
		continue;
	}

	// If both a from and to party exist within the CRM:
	if(!empty($formattedCall['from_party']) && !empty($formattedCall['to_party'])) {
		if(!hasExistingNote($formattedCall['from_party']['id'], $formattedCall['timestamp'])) {
			$capsuleApi->addNote($formattedCall['from_party']['id'], makeNote('outbound', $formattedCall));
		}
		
		if(!hasExistingNote($formattedCall['to_party']['id'], $formattedCall['timestamp'])) {
			$capsuleApi->addNote($formattedCall['to_party']['id'], makeNote('inbound', $formattedCall));
		}
		continue;
	}

	// If just a to party exists within the CRM:
	if(!empty($formattedCall['to_party']) && empty($formattedCall['from_party'])) {
		if(!hasExistingNote($formattedCall['to_party']['id'], $formattedCall['timestamp'])) {
			$capsuleApi->addNote($formattedCall['to_party']['id'], makeNote('inbound', $formattedCall));
			continue;
		} 
	}
	// If just a from party exists within the CRM:
	if(empty($formattedCall['to_party']) && !empty($formattedCall['from_party'])) {
		if(!hasExistingNote($formattedCall['from_party']['id'], $formattedCall['timestamp'])) {
			$capsuleApi->addNote($formattedCall['from_party']['id'], makeNote('outbound', $formattedCall));
			continue;
		} 
	}
}

/**
 * Takes an array of CDR data from the Synthesis API and parses it into a
 * less verbose form.
 * @param  Array $calls CDR Data from the Synthesis API.
 * @return Array        The parsed array of data.
 */
function formatCalls($calls) {
	$formattedCalls = array();
	foreach ($calls as $call) {
		switch ($call['direction']) {
			case 'inbound':
				array_push($formattedCalls, array(
					'from_number' => $call['clid'],
					'to_number' => $call['dnis'],
					'timestamp' => $call['time_utc'],
					'duration' => $call['length'],
					'guid' => $call['guid']
				));
				break;
			case 'outbound':
				array_push($formattedCalls, array(
					'from_number' => $call['clid'],
					'to_number' => $call['dnis'],
					'timestamp' => $call['time_utc'],
					'duration' => $call['length'],
					'guid' => $call['guid']
				));
				break;
		}
	}
	return $formattedCalls;
}

/**
 * Creates a Capsule historyItem from a specified call direction and a single formatted CDR.
 * @param  String $direction Expected input either 'inbound' or 'outbound', pertains to the direction of the call a note is being made for.
 * @param  Array  $noteData  Call data formatted by the formatCalls() function.
 * @return Array             Returns a formatted Capsule historyItem,
 */
function makeNote($direction, $noteData) {

	switch ($direction) {
		case 'inbound':
			$note = $noteData['to_party']['name'].' ('.$noteData['to_number'].") was called by ".
			$noteData['from_party']['name'].' ('.$noteData['from_number'].'). 
			Call duration: '.$noteData['duration'].' seconds';
			break;
		case 'outbound':
			$note = $noteData['from_party']['name'].' ('.$noteData['from_number'].") called ".
			$noteData['to_party']['name'].' ('.$noteData['to_number'].'). 
			Call duration: '.$noteData['duration'].' seconds';
			break;
		default:
			return 'Error: Invalid direction specified';
	}

	return $payload = array(
		'historyItem' => array(
			'note' => $note,
			'entryDate' => $noteData['timestamp']
		)
	);
}

/**
 * Searches existing parties for a given telephone number and returns the first match. Else returns false
 * @param  String $number 
 * @return Array         Data for the matched party.
 */
function sanitiseParty($party = null) {
	
	if(!$party) {
		return array(
			'id' => '',
			'name' => ''
		);
	}

	foreach ($party as $key => $value) {
		if($key == 'person') {
			return array(
				'id' => $party['person']['id'],
				'name' => $party['person']['firstName'].' '.$party['person']['lastName']
			);
		}
		elseif ($key == 'organisation') {
			return array(
				'id' => $party['organisation']['id'],
				'name' => $party['organisation']['name']
			);
		}
	}
	return false;
}

/**
 * Checks for existing notes for a given party, at a given time. Used to prevent the duplication of notes. 
 * @param  String  $partyID   UID of the searched party.
 * @param  Date    $dateTime  Date Object in ISO 8601 format.
 * @return boolean            If any are found returns true, else returns false.
 */
function hasExistingNote($partyID, $dateTime) {
	global $capsuleApi;
	$notes = $capsuleApi->getNotes($partyID);
	if(!$notes) return false;
	foreach($notes['history']['historyItem'] as $note) {
		if(strtotime($note['entryDate']) == strtotime($dateTime)) {
			return true;
		}
	}
	return false;
}

/**
 * Removes special characters, whitespace, country codes and international prefixes from a given number.
 * @param  String $num 		   Number to strip.
 * @return String/Boolean      Returns the stripped number. If the parameter passed to the function is not a number, returns false.
 */
function stripNumber($num, $returnE164 = false) {
	
	$num = preg_replace('/[^0-9]/','',$num);
	$prefix = substr($num, 0, 2);

	if($prefix == '44') {
		$strippedNumber = substr($num, 2);
	} 
	else if($prefix == '00') {
		$strippedNumber = substr($num, 4);
	} 
	else if(substr($num, 0, 1) == '0') {
		$strippedNumber = substr($num, 1);
	}
	else if(is_numeric($num)) {
		$strippedNumber = $num;
	}
	
	if($returnE164) return '44'.$strippedNumber;
	else return $strippedNumber;
}
