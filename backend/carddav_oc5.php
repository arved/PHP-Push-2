<?php
/***********************************************
* File      :   CardDAV_OC5.php
* part      :   backend
* Project   :   SOGoSync / OwnCloud
* Descr     :   This backend is based on
*               'BackendDiff' and implements an
*               CardDAV interface
                e.g. for SabreDAV or OwnCloud
*
* Created   :   29.03.2012
*
* Copyright 2012 <xbgmsharp@gmail.com>
* Copyright 2013 arved <arved@github.com>
* Copyright 2013 Stefan Schallenberg <nafets227@gitgub.com>
*
* Modified by Francisco Miguel Biete <fmbiete@gmail.com>
* Work with DaviCal. Limited to 1 addressbook/principal
*
************************************************/

include_once('lib/default/diffbackend/diffbackend.php');
include_once('include/carddav_iOC5.php');
include_once('include/z_RTF.php');
include_once('include/vCard.php');

class BackendCardDAV_OC5 extends BackendDiff implements ISearchProvider {
	// SOGoSync version
	const SOGOSYNC_VERSION = '0.4.0';
	// SOGoSync vcard Prodid
	const SOGOSYNC_PRODID = 'SOGoSync';

	private $_carddav;
	private $_collection = array ();
	private $url = null;
	private $server = null;
	
	// Android only supports synchronizing 1 AddressBook per account
	private $foldername = CARDDAV_CONTACTS_FOLDER_NAME_OC5;
	
	/**
	 * Login to the CardDAV backend
	 * 
	 * @see IBackend::Logon()
	 */
	public function Logon($username, $domain, $password)
	{
		// Confirm PHP-CURL Installed; If Not, Exit
		if (!function_exists("curl_init")) {
			ZLog::Write(LOGLEVEL_ERROR, sprintf("ERROR: Carddav Backend OC5 requires PHP-CURL"));
			return false;
		}
		// Android only supports synchronizing 1 AddressBook per account
		$this->foldername = CARDDAV_CONTACTS_FOLDER_NAME_OC5;
		$url = CARDDAV_PROTOCOL_OC5 . '://' . CARDDAV_SERVER_OC5 . ':' . CARDDAV_PORT_OC5 . str_replace ( "%d", $domain, str_replace ( "%u", $username, CARDDAV_PATH_OC5 ) );
		ZLog::Write(LOGLEVEL_INFO, sprintf("BackendCardDAV->Logon('%s')", $url));
		$this->_carddav = new carddav_backend($url);
		$this->_carddav->set_auth($username, $password);

		if ($this->_carddav->check_connection())
		{
			ZLog::Write(LOGLEVEL_INFO, sprintf("BackendCardDAV_OC5->Logon(): User '%s' is authenticated on CardDAV", $username));
			$this->url = $url;
			$this->ReloadCollection ( CARDDAV_CONTACTS_FOLDER_NAME_OC5 );
			return true;
		}
		else
		{
			ZLog::Write(LOGLEVEL_INFO, sprintf("BackendCardDAV_OC5->Logon(): User '%s' is not authenticated on CardDAV", $username));
			return false;
		}
	}

	/**
	 * The connections to CardDAV are always directly closed.
	 * So nothing special needs to happen here.
	 * @see IBackend::Logoff()
	 */
	public function Logoff()
	{
		$this->_carddav = null;
		unset ( $this->_collection );
		return true;
	}
	
	/**
	 * Indicates if the backend has a ChangesSink.
	 * A sink is an active notification mechanism which does not need polling.
	 * The CardDAV backend simulates a sink by polling revision dates from the vcards
	 *
	 * @access public
	 * @return boolean
	 */
	public function HasChangesSink() {
		return true;
	}
	
	/**
	 * The folder should be considered by the sink.
	 * Folders which were not initialized should not result in a notification
	 * of IBackend->ChangesSink().
	 *
	 * @param string $folderid        	
	 *
	 * @access public
	 * @return boolean false if found can not be found
	 */
	public function ChangesSinkInitialize($folderid) {
		ZLog::Write ( LOGLEVEL_DEBUG, sprintf ( "BackendCardDAV_OC5->ChangesSinkInitialize(): folderid '%s'", $folderid ) );
		
		$this->changessinkinit = true;
		$url = $this->url . $folderid . "/";
		$this->_carddav->set_url ( $url );
		// We don't need the actual cards, we only need to get the changes since this moment
		// FIXME: we need to get the changes since the last actual sync
		
		$vcards = false;
		try {
			$vcards = $this->_carddav->get_all_vcards ( false, false );
		} catch ( Exception $ex ) {
			ZLog::Write ( LOGLEVEL_ERROR, sprintf ( "BackendCardDAV_OC5->ChangesSinkInitialize - Error doing the initial sync: %s", $ex->getMessage () ) );
		}
		
		if ($vcards === false) {
			ZLog::Write ( LOGLEVEL_ERROR, sprintf ( "BackendCardDAV_OC5->ChangesSinkInitialize - Error initializing the sink" ) );
			return false;
		}
		
		unset ( $vcards );
		
		return true;
	}
	
	/**
	 * The actual ChangesSink.
	 * For max. the $timeout value this method should block and if no changes
	 * are available return an empty array.
	 * If changes are available a list of folderids is expected.
	 *
	 * @param int $timeout
	 *        	max. amount of seconds to block
	 *        	
	 * @access public
	 * @return array
	 */
	public function ChangesSink($timeout = 30) {
		$notifications = array ();
		$stopat = time () + $timeout - 1;
		$changed = false;
		// We can get here and the ChangesSink not be initialized yet
		if (! $this->changessinkinit) {
			ZLog::Write ( LOGLEVEL_DEBUG, sprintf ( "BackendCardDAV_OC5->ChangesSink - Not initialized ChangesSink, exiting" ) );
			return $notifications;
		}
		
		while ( $stopat > time () && empty ( $notifications ) ) {
			$vcards = false;
			try {
				$vcards = $this->_carddav->get_all_vcards ( false, false );
			} catch ( Exception $ex ) {
				ZLog::Write ( LOGLEVEL_ERROR, sprintf ( "BackendCardDAV_OC5->ChangesSink - Error resyncing vcards: %s", $ex->getMessage () ) );
			}
			
			if ($vcards === false) {
				ZLog::Write ( LOGLEVEL_ERROR, sprintf ( "BackendCardDAV_OC5->ChangesSink - Error getting the changes" ) );
				return false;
			} else {
				$xml_vcards = new SimpleXMLElement ( $vcards );
				unset ( $vcards );
				$check = array ();
				foreach ( $xml_vcards->element as $card ) {
					$id = ( string ) $card->id->__toString ();
					$check [$id] = $card;
				}
				if ($check != $this->_collection) {
					$changed = true;
					ZLog::Write ( LOGLEVEL_DEBUG, sprintf ( "BackendCardDAV_OC5->ChangesSink - Changes detected" ) );
				}
				unset ( $xml_vcards );
			}
			
			if ($changed) {
				$notifications [] = $this->foldername;
			}
			
			if (empty ( $notifications )) {
				// three trials per interval shoud be enough
				sleep ( ($timeout - 1) / 3 );
			}
		}
		
		return $notifications;
	}
	
	/**
	 * CardDAV doesn't need to handle SendMail
	 * @see IBackend::SendMail()
	 */
	public function SendMail($sm)
	{
		return false;
	}
	
	/**
	 * No attachments in CardDAV
	 * @see IBackend::GetAttachmentData()
	 */
	public function GetAttachmentData($attname)
	{
		return false;
	}

	/**
	 * Deletes are always permanent deletes.
	 * Messages doesn't get moved.
	 * @see IBackend::GetWasteBasket()
	 */
	public function GetWasteBasket()
	{
		return false;
	}

	/**
	 * Only 1 addressbook allowed.
	 * @see BackendDiff::GetFolderList()
	 */
	public function GetFolderList()
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->GetFolderList(): Getting all folders."));

		$folderlist = array();
		$folderlist[] = $this->StatFolder($this->foldername );

		return $folderlist;
	}

	/**
	 * Returning a SyncFolder
	 * @see BackendDiff::GetFolder()
	 */
	public function GetFolder($id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->GetFolder('%s', '%s')", $id, $this->foldername ) );

		if ($id == $this->foldername)
		{
			$folder = new SyncFolder ();
			$folder->serverid = $id;
			$folder->parentid = "0";
			$folder->displayname = "owncloud AddressBook";
			$folder->type = SYNC_FOLDER_TYPE_CONTACT;

		}
		else
			$folder = false;
				
		return $folder;
	}

	/**
	 * Returns information on the folder.
	 * @see BackendDiff::StatFolder()
	 */
	public function StatFolder($id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->StatFolder('%s')", $id));

		$val = $this->GetFolder($id);
		$folder = array();
		$folder["id"] = $id;
		$folder["parent"] = $val->parentid;
		$folder["mod"] = $val->displayname;
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->StatFolder(Abook Id [%s] Abook Name [%s])", $folder["id"], $folder["mod"]));
		return $folder;
	}

	/**
	 * ChangeFolder is not supported under CardDAV
	 * @see BackendDiff::ChangeFolder()
	 */
	public function ChangeFolder($folderid, $oldid, $displayname, $type)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->ChangeFolder('%s','%s','%s','%s')", $folderid, $oldid, $displayname, $type));
		return false;
	}

	/**
	 * DeleteFolder is not supported under CardDAV
	 * @see BackendDiff::DeleteFolder()
	 */
	public function DeleteFolder($id, $parentid)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->DeleteFolder('%s','%s')", $id, $parentid));
		return false;
	}

	/**
	 * Get a list of all the messages.
	 * @see BackendDiff::GetMessageList()
	 */
	public function GetMessageList($folderid, $cutoffdate)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->GetMessageList('%s','%s')", $folderid, $cutoffdate));

		if ((CARDDAV_SYNC_ON_PING_OC5) || (empty ( $this->_collection ))) {
			$this->ReloadCollection ( $folderid );
		}
		$messagelist = array ();
		if (empty ( $this->_collection )) {
			ZLog::Write(LOGLEVEL_WARN, sprintf("BackendCardDAV_OC5->GetMessageList(): Empty AddressBook"));
			return $messagelist;
		}
		foreach ( $this->_collection as $vcard )
		{
			$id = (string)$vcard->id->__toString();
			ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->GetMessageList(Add vcard to collection '%s')", $vcard->vcard->__toString()));
			$messagelist[] = $this->StatMessage($folderid, $id);
		}
		return $messagelist;
	}

	/**
	 * Get a SyncObject by its ID
	 * @see BackendDiff::GetMessage()
	 */
	public function GetMessage($folderid, $id, $contentparameters)
	{
		// for one vcard ($id) of one addressbook ($folderid)
		// send all vcard details in a SyncContact format
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->GetMessage('%s','%s')", $folderid,  $id));

		$data = null;
		// We have an ID and the vcard data
		if (array_key_exists($id, $this->_collection) && isset($this->_collection[$id]->vcard) && isset ( $this->_collection [$id]->etag ))
		{
			ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->GetMessage(array_key_exists and vcard)"));
		}
		else
		{
			$url = $this->url . $folderid . "/";
			$this->_carddav->set_url($url);
			ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->GetMessage('%s')", $url));
			$xmldata = $this->_carddav->get_xml_vcard($id);
			if ($xmldata === false)
			{
				ZLog::Write(LOGLEVEL_WARN, sprintf("BackendCardDAV_OC5->GetMessage(): vCard not found"));
				return false;
			}

			$xmlvcard = new SimpleXMLElement($xmldata);
			foreach($xmlvcard->element as $vcard)
			{
				$this->_collection[$id] = $vcard;
			}
		}
		$data = (string)$this->_collection[$id]->vcard->__toString();
		return $this->_ParseVCardToAS($data, $contentparameters);
	}

	/**
	 * Return id, flags and mod of a messageid
	 * @see BackendDiff::StatMessage()
	 */
	public function StatMessage($folderid, $id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->StatMessage('%s','%s')", $folderid, $id));

		// for one vcard ($id) of one addressbook ($folderid)
		// send the etag as mod and the UUID as id
		// the same as in GetMsgList

		$data = array ();
		// We have an ID and no vcard data
		if (array_key_exists($id, $this->_collection) && isset($this->_collection[$id]->id) && isset($this->_collection[$id]->etag))
		{
			ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->StatMessage(array_key_exists)"));
		}
		else
		{
			$url = $this->url . $folderid . "/";
			$this->_carddav->set_url($url);
			ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->StatMessage('%s')", $url));
			$xmldata = $this->_carddav->get_xml_vcard($id);
			if ($xmldata === false)
			{
				ZLog::Write(LOGLEVEL_WARN, sprintf("BackendCardDAV_OC5->StatMessage(): VCard not found"));
				return false;
			}
			$xmlvcard = new SimpleXMLElement($xmldata);
			foreach($xmlvcard->element as $vcard)
			{
				$this->_collection[$id] = $vcard;
			}
			ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->StatMessage(get_xml_vcard true)"));
		}
		$data = $this->_collection[$id];
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->StatMessage(id '%s', mod '%s')", $data->id->__toString(), $data->etag->__toString()));
		$message = array();
		$message['id'] = (string)$data->id->__toString();
		$message['flags'] = "1";
		$message['mod'] = (string)$data->etag->__toString();
		return $message;
	}

	/**
	 * Change/Add a message with contents received from ActiveSync
	 * @see BackendDiff::ChangeMessage()
	 */
	public function ChangeMessage($folderid, $id = null, $message)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->ChangeMessage('%s','%s')", $folderid, $id));
		if (!(defined(CARDDAV_READONLY_OC5 )))
		{
			define ( CARDDAV_READONLY_OC5, 'false' );
		}
		if (CARDDAV_READONLY_OC5) {
			return false;
		}
		
		$data = null;
		$data = $this->_ParseASCardToVCard($message);
		
		$url = $this->url . $folderid . "/";
		$this->_carddav->set_url($url);
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->ChangeMessage('%s')", $url));

		$result = $this->_carddav->update ( $data, $id );
		if ($result)
		{
			return $this->StatMessage ( $folderid, $result );
		}
		else
		{
			return false;
		}
	}

	/**
	 * Change the read flag is not supported.
	 * @see BackendDiff::SetReadFlag()
	 */
	public function SetReadFlag($folderid, $id, $flags)
	{
		return false;
	}

	/**
	 * Delete a message from the CardDAV server.
	 * @see BackendDiff::DeleteMessage()
	 */
	public function DeleteMessage($folderid, $id)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->DeleteMessage('%s','%s')", $folderid, $id));
		if (! defined(CARDDAV_READONLY_OC5 ))
		{
			define ( CARDDAV_READONLY_OC5, 'false' );
		}
		if (CARDDAV_READONLY_OC5)
		{
			return false;
		}
		$url = $this->url . $folderid . "/";
		$this->_carddav->set_url($url);
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->DeleteMessage('%s')", $url));
		unset ( $this->_collection [$id] );
		return $this->_carddav->delete($id);
	}

	/**
	 * Move a message is not supported by CardDAV.
	 * @see BackendDiff::MoveMessage()
	 */
	public function MoveMessage($folderid, $id, $newfolderid)
	{
		return false;
	}
	
	
	/**
	 * Disconnects from CardDAV
	 *
	 * @access public
	 * @return boolean
	 */
	public function Disconnect() {
		return true;
	}

	/**
	 * @TODO Describe function ReloadCollection
	 */
	private function ReloadCollection($folderid) {
		ZLog::Write ( LOGLEVEL_DEBUG, sprintf ( "BackendCardDAV_OC5->ReloadCollection('%s')", $folderid ) );
		$vardlist = false;
		$url = $this->url . $folderid . "/";
		$this->_carddav->set_url ( $url );
		try {
			$vcardlist = $this->_carddav->get_all_vcards ( false, false );
		} catch ( Exception $ex ) {
			ZLog::Write ( LOGLEVEL_ERROR, sprintf ( "BackendCardDAV_OC5->ReloadCollection() - Error getting the vcards: '%s'", $ex->getMessage () ) );
			return false;
		}
		if ($vcardlist === false) {
			ZLog::Write ( LOGLEVEL_DEBUG, sprintf ( "BackendCardDAV_OC5->ReloadCollection(): Empty AddressBook" ) );
			return false;
		} else {
			$xmlvcardlist = new SimpleXMLElement ( $vcardlist );
			$this->_collection = array ();
			foreach ( $xmlvcardlist->element as $vcard ) {
				$id = ( string ) $vcard->id->__toString ();
				$this->_collection [$id] = $vcard;
				// ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->ReloadCollection(): id: '%s', etag: '%s'" , $id, ((string)$this->_collection[$id]->etag->__toString()) ));
			}
			// ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->ReloadCollection(): Cache (re)filled: '%s'", print_r($this->_collection, true)));
			return true;
		}
		// ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->ReloadCollection(): end"));
	}

	/**
	 * @TODO Describe function ReloadCollection
	 */
	private function escape($data) {
		if (is_array ( $data )) {
			foreach ( $data as $key => $val ) {
				$data [$key] = $this->escape ( $val );
			}
			return $data;
		}
		$data = str_replace ( "\r\n", "\n", $data );
		$data = str_replace ( "\r", "\n", $data );
		$data = str_replace ( array (
				'\\',
				';',
				',',
				"\n" 
		), array (
				'\\\\',
				'\\;',
				'\\,',
				'\\n' 
		), $data );
		return $data;
	}
	
	/**
	 * Un-escapes a string
	 *
	 * @param string $data
	 *        	string to be un-escaped
	 *        	
	 * @access private
	 * @return string
	 */
	private function unescape($data) {
		if (is_array ( $data )) {
			foreach ( $data as $key => $val ) {
				$data [$key] = $this->unescape ( $val );
			}
			return $data;
		}
		$data = str_replace ( array (
				'\\\\',
				'\\;',
				'\\,',
				'\\n',
				'\\N' 
		), array (
				'\\',
				';',
				',',
				"\n",
				"\n" 
		), $data );
		return $data;
	}
	
	/**
	 * Convert a VCard to ActiveSync format
	 * @param vcard $data
	 * @param ContentParameters $contentparameters
	 * @return SyncContact
	 */
	private function _ParseVCardToAS($data, $contentparameters)
	{
		ZLog::Write(LOGLEVEL_WBXML, sprintf("BackendCardDAV->_ParseVCardToAS(vCard[%s])", $data));
		$truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());
		$types = array (
				'dom' => 'type',
				'intl' => 'type',
				'postal' => 'type',
				'parcel' => 'type',
				'home' => 'type',
				'work' => 'type',
				'pref' => 'type',
				'voice' => 'type',
				'fax' => 'type',
				'msg' => 'type',
				'cell' => 'type',
				'pager' => 'type',
				'bbs' => 'type',
				'modem' => 'type',
				'car' => 'type',
				'isdn' => 'type',
				'video' => 'type',
				'aol' => 'type',
				'applelink' => 'type',
				'attmail' => 'type',
				'cis' => 'type',
				'eworld' => 'type',
				'internet' => 'type',
				'ibmmail' => 'type',
				'mcimail' => 'type',
				'powershare' => 'type',
				'prodigy' => 'type',
				'tlx' => 'type',
				'x400' => 'type',
				'gif' => 'type',
				'cgm' => 'type',
				'wmf' => 'type',
				'bmp' => 'type',
				'met' => 'type',
				'pmb' => 'type',
				'dib' => 'type',
				'pict' => 'type',
				'tiff' => 'type',
				'pdf' => 'type',
				'ps' => 'type',
				'jpeg' => 'type',
				'qtime' => 'type',
				'mpeg' => 'type',
				'mpeg2' => 'type',
				'avi' => 'type',
				'wave' => 'type',
				'aiff' => 'type',
				'pcm' => 'type',
				'x509' => 'type',
				'pgp' => 'type',
				'text' => 'value',
				'inline' => 'value',
				'url' => 'value',
				'cid' => 'value',
				'content-id' => 'value',
				'7bit' => 'encoding',
				'8bit' => 'encoding',
				'quoted-printable' => 'encoding',
				'base64' => 'encoding' 
		);

		// Parse the vcard
		$message = new SyncContact();

		$data = str_replace ( "\x00", '', $data );
		$data = str_replace ( "\r\n", "\n", $data );
		$data = str_replace ( "\r", "\n", $data );
		$data = preg_replace ( '/(\n)([ \t])/i', '', $data );
		
		$lines = explode ( "\n", $data );
		
		$vcard = array ();
		foreach ( $lines as $line ) {
			if (trim ( $line ) == '')
				continue;
			$pos = strpos ( $line, ':' );
			if ($pos === false)
				continue;
			
			$field = trim ( substr ( $line, 0, $pos ) );
			$value = trim ( substr ( $line, $pos + 1 ) );
			
			$fieldparts = preg_split ( '/(?<!\\\\)(\;)/i', $field, - 1, PREG_SPLIT_NO_EMPTY );
			
			$type = strtolower ( array_shift ( $fieldparts ) );
			
			$fieldvalue = array ();
			
			foreach ( $fieldparts as $fieldpart ) {
				if (preg_match ( '/([^=]+)=(.+)/', $fieldpart, $matches )) {
					if (! in_array ( strtolower ( $matches [1] ), array (
							'value',
							'type',
							'encoding',
							'language' 
					) ))
						continue;
					if (isset ( $fieldvalue [strtolower ( $matches [1] )] ) && is_array ( $fieldvalue [strtolower ( $matches [1] )] )) {
						$fieldvalue [strtolower ( $matches [1] )] = array_merge ( $fieldvalue [strtolower ( $matches [1] )], preg_split ( '/(?<!\\\\)(\,)/i', $matches [2], - 1, PREG_SPLIT_NO_EMPTY ) );
					} else {
						$fieldvalue [strtolower ( $matches [1] )] = preg_split ( '/(?<!\\\\)(\,)/i', $matches [2], - 1, PREG_SPLIT_NO_EMPTY );
					}
				} else {
					if (! isset ( $types [strtolower ( $fieldpart )] ))
						continue;
					$fieldvalue [$types [strtolower ( $fieldpart )]] [] = $fieldpart;
				}
			}
			//
			switch ($type) {
				case 'categories' :
					$val = preg_split ( '/(?<!\\\\)(\,)/i', $value );
					break;
				default :
					$val = preg_split ( '/(?<!\\\\)(\;)/i', $value );
					break;
			}
			if (isset ( $fieldvalue ['encoding'] [0] )) {
				switch (strtolower ( $fieldvalue ['encoding'] [0] )) {
					case 'q' :
					case 'quoted-printable' :
						foreach ( $val as $i => $v ) {
							$val [$i] = quoted_printable_decode ( $v );
						}
						break;
					case 'b' :
					case 'base64' :
						foreach ( $val as $i => $v ) {
							$val [$i] = base64_decode ( $v );
						}
						break;
				}
			} else {
				foreach ( $val as $i => $v ) {
					$val [$i] = $this->unescape ( $v );
				}
			}
			$fieldvalue ['val'] = $val;
			$vcard [$type] [] = $fieldvalue;
		}
		
		if (isset ( $vcard ['email'] )) {
			foreach ( $vcard ['email'] as $mail ) {
				if (! empty ( $mail ['val'] [0] )) {
					If (empty ( $message->email1address )) {
						$message->email1address = $mail ['val'] [0];
					} elseif (empty ( $message->email2address )) {
						$message->email2address = $mail ['val'] [0];
					} elseif (empty ( $message->email3address )) {
						$message->email3address = $mail ['val'] [0];
					} elseif ((strpos ( $vcard ['note'] [0] ['val'] [0], $mail ['val'] [0] )) === false) {
						$vcard ['note'] [0] ['val'] [0] = 'EMAIL#TYPE=' . $mail ['type'] [0] . ':' . $mail ['val'] [0] . chr ( 13 ) . chr ( 10 ) . $vcard ['note'] [0] ['val'] [0];
					}
				}
			}
		}
		if (isset ( $vcard ['impp'] )) {
			foreach ( $vcard ['impp'] as $impp ) {
				if (! empty ( $impp ['val'] [0] )) {
					If (empty ( $message->imaddress )) {
						$message->imaddress = $impp ['val'] [0];
					} elseif (empty ( $message->imaddress2 )) {
						$message->imaddress2 = $impp ['val'] [0];
					} elseif (empty ( $message->imaddress3 )) {
						$message->imaddress3 = $impp ['val'] [0];
					} elseif ((strpos ( $vcard ['note'] [0] ['val'] [0], $impp ['val'] [0] )) === false) {
						$vcard ['note'] [0] ['val'] [0] = 'IMPP#TYPE=' . $impp ['type'] [0] . ':' . $impp ['val'] [0] . chr ( 13 ) . chr ( 10 ) . $vcard ['note'] [0] ['val'] [0];
					}
				}
			}
		}
		if (isset ( $vcard ['tel'] )) {
			foreach ( $vcard ['tel'] as $tel ) {
				if (is_array ( $tel ['type'] )) {
					if (in_array ( 'HOME', $tel ['type'] )) {
						if (empty ( $message->homephonenumber )) {
							$message->homephonenumber = $tel ['val'] [0];
						} elseif (empty ( $message->home2phonenumber )) {
							if (in_array ( 'PREF', $tel ['type'] )) {
								$message->home2phonenumber = $message->homephonenumber;
								$message->homephonenumber = $tel ['val'] [0];
							} else {
								$message->home2phonenumber = $tel ['val'] [0];
							}
						} elseif ((strpos ( $vcard ['note'] [0] ['val'] [0], $tel ['val'] [0] )) === false) {
							$vcard ['note'] [0] ['val'] [0] = 'TEL#TYPE=HOME;TYPE=OTHER:' . $tel ['val'] [0] . chr ( 13 ) . chr ( 10 ) . $vcard ['note'] [0] ['val'] [0];
						}
					} elseif (in_array ( 'CAR', $tel ['type'] )) {
						if (empty ( $message->carphonenumber )) {
							$message->carphonenumber = $tel ['val'] [0];
						} elseif ((strpos ( $vcard ['note'] [0] ['val'] [0], $tel ['val'] [0] )) === false) {
							$vcard ['note'] [0] ['val'] [0] = 'TEL#TYPE=CAR#TYPE=OTHER:' . $tel ['val'] [0] . chr ( 13 ) . chr ( 10 ) . $vcard ['note'] [0] ['val'] [0];
						}
					} elseif (in_array ( 'PAGER', $tel ['type'] )) {
						if (empty ( $message->pagernumber )) {
							$message->pagernumber = $tel ['val'] [0];
						} elseif ((strpos ( $vcard ['note'] [0] ['val'] [0], $tel ['val'] [0] )) === false) {
							$vcard ['note'] [0] ['val'] [0] = 'TEL#TYPE=PAGER#TYPE=OTHER:' . $tel ['val'] [0] . chr ( 13 ) . chr ( 10 ) . $vcard ['note'] [0] ['val'] [0];
						}
					} elseif (in_array ( 'CELL', $tel ['type'] )) {
						if (empty ( $message->mobilephonenumber )) {
							$message->mobilephonenumber = $tel ['val'] [0];
						} elseif ((strpos ( $vcard ['note'] [0] ['val'] [0], $tel ['val'] [0] ) === false)) {
							$vcard ['note'] [0] ['val'] [0] = 'TEL#TYPE=CELL#TYPE=OTHER:' . $tel ['val'] [0] . chr ( 13 ) . chr ( 10 ) . $vcard ['note'] [0] ['val'] [0];
						}
					} elseif (in_array ( 'WORK', $tel ['type'] )) {
						if (empty ( $message->businessphonenumber )) {
							$message->businessphonenumber = $tel ['val'] [0];
						} elseif (empty ( $message->business2phonenumber )) {
							if (in_array ( 'PREF', $tel ['type'] )) {
								$message->business2phonenumber = $message->businessphonenumber;
								$message->businessphonenumber = $tel ['val'] [0];
							} else {
								$message->business2phonenumber = $tel ['val'] [0];
							}
						} elseif (empty ( $message->companymainphone )) {
							$message->companymainphone = $tel ['val'] [0];
						} elseif ((strpos ( $vcard ['note'] [0] ['val'] [0], $tel ['val'] [0] )) === false) {
							$vcard ['note'] [0] ['val'] [0] = 'TEL#TYPE=WORK#TYPE=OTHER:' . $tel ['val'] [0] . chr ( 13 ) . chr ( 10 ) . $vcard ['note'] [0] ['val'] [0];
						}
					} elseif (in_array ( 'FAX', $tel ['type'] )) {
						if (in_array ( 'HOME', $tel ['type'] )) {
							if (empty ( $message->homefaxnumber )) {
								$message->homefaxnumber = $tel ['val'] [0];
							} elseif ((strpos ( $vcard ['note'] [0] ['val'] [0], $tel ['val'] [0] )) === false) {
								$vcard ['note'] [0] ['val'] [0] = 'TEL#TYPE=FAX#TYPE=HOME#TYPE=OTHER:' . $tel ['val'] [0] . chr ( 13 ) . chr ( 10 ) . $vcard ['note'] [0] ['val'] [0];
							}
						} elseif (in_array ( 'WORK', $tel ['type'] )) {
							if (empty ( $message->businessfaxnumber )) {
								$message->businessfaxnumber = $tel ['val'] [0];
							} elseif ((strpos ( $vcard ['note'] [0] ['val'] [0], $tel ['val'] [0] )) === false) {
								$vcard ['note'] [0] ['val'] [0] = 'TEL#TYPE=FAX#TYPE=WORK#TYPE=OTHER:' . $tel ['val'] [0] . chr ( 13 ) . chr ( 10 ) . $vcard ['note'] [0] ['val'] [0];
							}
						} else {
							if (empty ( $message->businessfaxnumber )) {
								$message->businessfaxnumber = $tel ['val'] [0];
							} elseif (empty ( $message->homefaxnumber )) {
								$message->homefaxnumber = $tel ['val'] [0];
							} elseif ((strpos ( $vcard ['note'] [0] ['val'] [0], $tel ['val'] [0] )) === false) {
								$vcard ['note'] [0] ['val'] [0] = 'TEL#TYPE=FAX#TYPE=OTHER:' . $tel ['val'] [0] . chr ( 13 ) . chr ( 10 ) . $vcard ['note'] [0] ['val'] [0];
							}
						}
					} elseif (in_array ( 'TEXT', $tel ['type'] )) {
						if (empty ( $message->mms )) {
							$message->mms = $tel ['val'] [0];
						} elseif ((strpos ( $vcard ['note'] [0] ['val'] [0], $tel ['val'] [0] )) === false) {
							$vcard ['note'] [0] ['val'] [0] = 'TEL#TYPE=TEXT#TYPE=OTHER:' . $tel ['val'] [0] . chr ( 13 ) . chr ( 10 ) . $vcard ['note'] [0] ['val'] [0];
						}
					} elseif (in_array ( 'OTHER', $tel ['type'] )) {
						if (empty ( $message->radiophonenumber )) {
							$message->radiophonenumber = $tel ['val'] [0];
						} elseif ((strpos ( $vcard ['note'] [0] ['val'] [0], $tel ['val'] [0] )) === false) {
							$vcard ['note'] [0] ['val'] [0] = 'TEL#TYPE=OTHER#TYPE=OTHER:' . $tel ['val'] [0] . chr ( 13 ) . chr ( 10 ) . $vcard ['note'] [0] ['val'] [0];
						}
					} elseif (in_array ( 'MSG', $tel ['type'] )) {
						if (empty ( $message->companymainphone )) {
							$message->companymainphone = $tel ['val'] [0];
						} elseif ((strpos ( $vcard ['note'] [0] ['val'] [0], $tel ['val'] [0] )) === false) {
							$vcard ['note'] [0] ['val'] [0] = 'TEL#TYPE=MSG#TYPE=OTHER:' . $tel ['val'] [0] . chr ( 13 ) . chr ( 10 ) . $vcard ['note'] [0] ['val'] [0];
						}
					} elseif (in_array ( 'VOICE', $tel ['type'] )) {
						if (empty ( $message->assistnamephonenumber )) {
							$message->assistnamephonenumber = $tel ['val'] [0];
						} elseif ((strpos ( $vcard ['note'] [0] ['val'] [0], $tel ['val'] [0] )) === false) {
							$vcard ['note'] [0] ['val'] [0] = 'TEL#TYPE=VOICE#TYPE=OTHER:' . $tel ['val'] [0] . chr ( 13 ) . chr ( 10 ) . $vcard ['note'] [0] ['val'] [0];
						}
					} elseif (strpos ( $vcard ['note'] [0] ['val'] [0], $tel ['val'] [0] ) === false) {
						$vcard ['note'] [0] ['val'] [0] = 'TEL:' . $tel ['val'] [0] . chr ( 13 ) . chr ( 10 ) . $vcard ['note'] [0] ['val'] [0];
					}
				}
			}
		}
		// ;;street;city;state;postalcode;country
		if (isset ( $vcard ['adr'] )) {
			foreach ( $vcard ['adr'] as $adr ) {
				if (empty ( $adr ['type'] )) {
					$a = 'other';
				} elseif (in_array ( 'HOME', $adr ['type'] )) {
					$a = 'home';
				} elseif (in_array ( 'WORK', $adr ['type'] )) {
					$a = 'business';
				} else {
					$a = 'other';
				}
				if (! empty ( $adr ['val'] [2] )) {
					$b = $a . 'street';
					$message->$b = $adr ['val'] [2];
				}
				if (! empty ( $adr ['val'] [3] )) {
					$b = $a . 'city';
					$message->$b = $adr ['val'] [3];
				}
				if (! empty ( $adr ['val'] [4] )) {
					$b = $a . 'state';
					$message->$b = $adr ['val'] [4];
				}
				if (! empty ( $adr ['val'] [5] )) {
					$b = $a . 'postalcode';
					$message->$b = $adr ['val'] [5];
				}
				if (! empty ( $adr ['val'] [6] )) {
					$b = $a . 'country';
					$message->$b = $adr ['val'] [6];
				}
			}
		}
		
		if (! empty ( $vcard ['fn'] [0] ['val'] [0] ))
			$message->fileas = $vcard ['fn'] [0] ['val'] [0];
		if (! empty ( $vcard ['n'] [0] ['val'] [0] ))
			$message->lastname = $vcard ['n'] [0] ['val'] [0];
		if (! empty ( $vcard ['n'] [0] ['val'] [1] ))
			$message->firstname = $vcard ['n'] [0] ['val'] [1];
		if (! empty ( $vcard ['n'] [0] ['val'] [2] ))
			$message->middlename = $vcard ['n'] [0] ['val'] [2];
		if (! empty ( $vcard ['n'] [0] ['val'] [3] ))
			$message->title = $vcard ['n'] [0] ['val'] [3];
		if (! empty ( $vcard ['n'] [0] ['val'] [4] ))
			$message->suffix = $vcard ['n'] [0] ['val'] [4];
		if (! empty ( $vcard ['nickname'] [0] ['val'] [0] ))
			$message->nickname = $vcard ['nickname'] [0] ['val'] [0];
		if (! empty ( $vcard ['bday'] [0] ['val'] [0] )) {
			$tz = date_default_timezone_get();
			date_default_timezone_set('UTC');
			$message->birthday = strtotime($vcard['bday'][0] ['val'] [0] );
			date_default_timezone_set($tz);
		}
		if (! empty ( $vcard ['org'] [0] ['val'] [0] ))
			$message->companyname = $vcard ['org'] [0] ['val'] [0];
		if (! empty ( $vcard ['role'] [0] ['val'] [0] ))
			$message->jobtitle = $vcard ['role'] [0] ['val'] [0];
		if (! empty ( $vcard ['url'] [0] ['val'] [0] ))
			$message->webpage = $vcard ['url'] [0] ['val'] [0];
		if (! empty ( $vcard ['x-spouse'] [0] ['val'] [0] ))
			$message->spouse = $vcard ['x-spouse'] [0] ['val'] [0];
		if (! empty ( $vcard ['x-manager'] [0] ['val'] [0] ))
			$message->managername = $vcard ['x-manager'] [0] ['val'] [0];
		if (! empty ( $vcard ['x-assistant'] [0] ['val'] [0] ))
			$message->assistantname = $vcard ['x-assistant'] [0] ['val'] [0];
		if (! empty ( $vcard ['categories'] [0] ['val'] ))
			$message->categories = $vcard ['categories'] [0] ['val'];
		if (! empty ( $vcard ['note'] [0] ['val'] [0] )) {
			if (Request::GetProtocolVersion () >= 12.0) {
				$message->asbody = new SyncBaseBody ();
				$message->asbody->type = SYNC_BODYPREFERENCE_PLAIN;
				$message->asbody->data = str_replace ( "\n ", "\n", $this->unescape ( $vcard ['note'] [0] ['val'] [0] ) );
				if ($truncsize > 0 && $truncsize < strlen ( $message->asbody->data )) {
					$message->asbody->truncated = 1;
					$message->asbody->data = Utils::Utf8_truncate ( $message->asbody->data, $truncsize );
				} else {
					$message->asbody->truncated = 0;
				}
				$message->asbody->estimatedDataSize = strlen ( $message->asbody->data );
			} else {
				$message->body = str_replace ( "\n ", "\n", $this->unescape ( $vcard ['note'] [0] ['val'] [0] ) );
				if ($truncsize > 0 && $truncsize < strlen ( $message->body )) {
					$message->bodytruncated = 1;
					$message->body = Utils::Utf8_truncate ( $message->body, $truncsize );
				} else {
					$message->bodytruncated = 0;
				}
				$message->bodysize = strlen ( $message->body );
			}
		}
		if (! empty ( $vcard ['photo'] [0] ['val'] [0] ))
			$message->picture = base64_encode ( $vcard ['photo'] [0] ['val'] [0] );
			// ZLog::Write(LOGLEVEL_WBXML, sprintf("BackendCardDAV_OC5->_ParseVCardToAS : vCard\n%s\n%s", $data, print_r($message, true)));
		
		return $message;
	}

	/**
	 * Generate a VCard from a SyncContact(Exception).
	 * @param string $data
	 * @param string $id
	 * @return VCard
	 */
	private function _ParseASCardToVCard($message)
	{
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->_ParseASCardToVCard()"));

		$adrmapping = array(
				';;businessstreet;businesscity;businessstate;businesspostalcode;businesscountry' => 'ADR;TYPE=WORK',
				';;homestreet;homecity;homestate;homepostalcode;homecountry' => 'ADR;TYPE=HOME',
				';;otherstreet;othercity;otherstate;otherpostalcode;othercountry' => 'ADR;TYPE=OTHER' 
		);
		$mapping = array(
				'lastname;firstname;middlename;title;suffix' => 'N',
				'email1address' => 'EMAIL;TYPE=WORK;TYPE=PREF',
				'email2address' => 'EMAIL;TYPE=HOME',
				'email3address' => 'EMAIL;TYPE=OTHER',
				'businessphonenumber' => 'TEL;TYPE=WORK;TYPE=PREF',
				'business2phonenumber' => 'TEL;TYPE=WORK',
				'companymainphone' => 'TEL;TYPE=MSG',
				'businessfaxnumber' => 'TEL;TYPE=FAX;TYPE=WORK',
				'homephonenumber' => 'TEL;TYPE=HOME;TYPE=PREF',
				'home2phonenumber' => 'TEL;TYPE=HOME',
				'homefaxnumber' => 'TEL;TYPE=FAX;TYPE=HOME',
				'mobilephonenumber' => 'TEL;TYPE=CELL',
				'carphonenumber' => 'TEL;TYPE=CAR',
				'pagernumber' => 'TEL;TYPE=PAGER',
				'mms' => 'TEL;TYPE=TEXT',
				'radiophonenumber' => 'TEL;TYPE=OTHER',
				'assistnamephonenumber' => 'TEL;TYPE=VOICE',
				'companyname' => 'ORG',
				'jobtitle' => 'ROLE',
				'webpage' => 'URL',
				'nickname' => 'NICKNAME',
				'imaddress' => 'IMPP',
				'imaddress2' => 'IMPP',
				'imaddress3' => 'IMPP',
				'spouse' => 'X-SPOUSE',
				'managername' => 'X-MANAGER',
				'assistantname' => 'X-ASSISTANT' 
		);
		// correcting EMail adress formats
		$message->email1address = trim($message->email1address, '"');
		$message->email2address = trim($message->email2address, '"');
		$message->email3address = trim($message->email3address, '"');
		if (isset($message->email1address))
		{
			if (strpos($message->email1address, "<" ) !== false)
				{
				$start = (strpos($message->email1address, "<") + 1);
				$length = (strlen($message->email1address ) - strpos($message->email1address, "<") - 2);
				$message->email1address = substr ( $message->email1address, $start, $length );
				$start = 0;
				$length = 0;
			}
		}
		if (isset ( $message->email2address ))
		{
			if (strpos ( $message->email2address, "<" ) !== false) {
				$start = (strpos ( $message->email2address, "<" ) + 1);
				$length = (strlen ( $message->email2address ) - strpos ( $message->email2address, "<" ) - 2);
				$message->email2address = substr ( $message->email2address, $start, $length );
				$start = 0;
				$length = 0;
			}
		}
		if (isset ( $message->email3address ))
		{
			if (strpos ( $message->email3address, "<" ) !== false)
			{
				$start = (strpos ( $message->email3address, "<" ) + 1);
				$length = (strlen ( $message->email3address ) - strpos ( $message->email3address, "<" ) - 2);
				$message->email3address = substr ( $message->email3address, $start, $length );
				$start = 0;
				$length = 0;
			}
		}

		// start baking the vcard
		$data = "BEGIN:VCARD\n";
		$data .= "VERSION:3.0\n";
		$data .= "PRODID:-//PHP-Push-2-owncloud-/0.3\n";

		if (empty ( $message->fileas ) || CARDDAV_FILEAS_ALLWAYSOVERRIDE_OC5 === true) {
			if (empty ( $message->company ))
			{
				$message->company = '';
			}
			$data .= 'FN:' . Utils::BuildFileAs ( $message->lastname, $message->firstname, $message->middlename, $message->company ) . "\n";
		} 
		else
		{
			$data .= 'FN:' . $message->fileas . "\n";
		}

		foreach($adrmapping as $adrk => $adrv )
		{
			$adrval = null;
			$adrks = explode(';', $adrk);
			foreach ( $adrks as $adri )
			{
				if ((! empty ( $message->$adri )) && ($message->$adri != '')) {
					$adrval .= $this->escape ( $message->$adri );
				}
				$adrval .= ';';
			}
			if ((empty ( $adrval )) || (str_replace ( ';', '', $adrval ) == ''))
				continue;
			
			$adrval = substr ( $adrval, 0, - 1 );
			if (strlen ( $adrval ) > 50)
			{
				$data .= $adrv . ":\n " . chunk_split ( $adrval, 50, "\n " );
				$data .= "\n";
			}
			else
			{
				$data .= $adrv . ':' . $adrval . "\n";
			}
		}
		foreach ( $mapping as $k => $v )
		{
			$val = null;
			$ks = explode ( ';', $k );
			foreach ( $ks as $i ) {
				if ((! empty ( $message->$i )) && ($message->$i != ''))
				{
					$val .= $this->escape ( $message->$i );
					$val .= ';';
				}
			}
			if (empty ( $val ))
				continue;
			
			$val = substr ( $val, 0, - 1 );
			if (strlen ( $val ) > 50)
			{
				$data .= $v;
				$data .= ":\n " . chunk_split ( $val, 50, "\n " );
				$data .= "\n";
			}
			else
			{
				$data .= $v . ':' . $val . "\n";
			}
		}
		if ((isset ( $message->birthday )) && (! empty ( $message->birthday )))
			$data .= 'BDAY:' . date ( 'Y-m-d', $message->birthday ) . "\n";
		
		if ((isset ( $message->categories )) && (! empty ( $message->categories )))
			$data .= 'CATEGORIES:' . implode ( ',', $this->escape ( $message->categories ) ) . "\n";
		
		if (Request::GetProtocolVersion () >= 12.0)
		{
			if ((isset ( $message->asbody->data )) && (! empty ( $message->asbody->data )))
			{
				if ($message->asbody->type == SYNC_BODYPREFERENCE_HTML)
				{
					$data .= 'NOTE:' . str_replace ( "\n", "\n ", $this->escape ( Utils::ConvertHtmlToText ( $message->asbody->data ) ) ) . "\n ";
				}
				else
				{
					$data .= 'NOTE:' . str_replace ( "\n", "\n ", $this->escape ( $message->asbody->data ) ) . "\n ";
				}
			}
		}
		else
		{
			if ((isset ( $message->body )) && (! empty ( $message->body )))
			{
				$data .= 'NOTE:' . str_replace ( "\n", "\n ", $this->escape ( $message->body ) ) . "\n ";
			}
		}
		if ((isset ( $message->picture )) && (! empty ( $message->picture )))
		{
			$data .= 'PHOTO;ENCODING=BASE64;TYPE=JPEG:' . "\n " . chunk_split ( $message->picture, 50, "\n " );
			$data .= "\n ";
		}
		$data .= "\nEND:VCARD\n";
		$data = str_replace ( "\n\n", "\n", $data );
		
		// http://en.wikipedia.org/wiki/VCard
		// TODO: add support for v4.0
		// not supported: anniversary, children, department, officelocation, radiophonenumber, rtf, yomicompanyname, yomifirstname, yomilastname, customerid, governmentid
		
		ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV->_ParseASCardToVCard('vCard[%s]", $data));
		return $data;
	}
	
	/**
	 * ----------------------------------------------------------------------------------------------------------
	 * public ISearchProvider methods
	 */
	
	/**
	 * Indicates if a search type is supported by this SearchProvider
	 * Currently only the type ISearchProvider::SEARCH_GAL (Global Address List) is implemented
	 *
	 * @param string $searchtype        	
	 *
	 * @access public
	 * @return boolean
	 */
	public function SupportsType($searchtype)
	{
		return ($searchtype == ISearchProvider::SEARCH_GAL);
	}
	
	
	/**
	 * Queries the CardDAV backend
	 *
	 * @param string $searchquery
	 *        	string to be searched for
	 * @param string $searchrange
	 *        	specified searchrange
	 *        	
	 * @access public
	 * @return array search results
	 */
	public function GetGALSearchResults($searchquery, $searchrange)
	{
		ZLog::Write ( LOGLEVEL_DEBUG, sprintf ( "BackendCardDAV_OC5->GetGALSearchResults(%s, %s)", $searchquery, $searchrange ) );
		if (isset ( $this->_carddav ) && $this->_carddav !== false)
		{
			if (strlen ( $searchquery ) < 5)
			{
				return false;
			}
			
			ZLog::Write ( LOGLEVEL_DEBUG, sprintf ( "BackendCardDAV_OC5->GetGALSearchResults searching: %s", $this->url ) );
			try
			{
				$this->_carddav->enable_debug ();
				$vcards = $this->_carddav->search_vcards ( str_replace ( "<", "", str_replace ( ">", "", $searchquery ) ), 15, true, false );
			}
			catch ( Exception $e )
			{
				$vcards = false;
				ZLog::Write ( LOGLEVEL_ERROR, sprintf ( "BackendCardDAV_OC5->GetGALSearchResults : Error in search %s", $e->getMessage () ) );
			}

			if ($vcards === false)
			{
				ZLog::Write ( LOGLEVEL_ERROR, "BackendCardDAV_OC5->GetGALSearchResults : Error in search query. Search aborted" );
				return false;
			}
			
			$xml_vcards = new SimpleXMLElement ( $vcards );
			unset ( $vcards );
			
			// range for the search results, default symbian range end is 50, wm 99,
			// so we'll use that of nokia
			$rangestart = 0;
			$rangeend = 50;
			
			if ($searchrange != '0')
			{
				$pos = strpos ( $searchrange, '-' );
				$rangestart = substr ( $searchrange, 0, $pos );
				$rangeend = substr ( $searchrange, ($pos + 1) );
			}
			$items = array ();
			
			// TODO the limiting of the searchresults could be refactored into Utils as it's probably used more than once
			$querycnt = $xml_vcards->count ();
			// do not return more results as requested in range
			$querylimit = (($rangeend + 1) < $querycnt) ? ($rangeend + 1) : $querycnt == 0 ? 1 : $querycnt;
			$items ['range'] = $rangestart . '-' . ($querylimit - 1);
			$items ['searchtotal'] = $querycnt;
			
			ZLog::Write ( LOGLEVEL_DEBUG, sprintf ( "BackendCardDAV_OC5->GetGALSearchResults : %s entries found, returning %s to %s", $querycnt, $rangestart, $querylimit ) );
			
			$i = 0;
			$rc = 0;
			foreach ( $xml_vcards->element as $xml_vcard )
			{
				if ($i >= $rangestart && $i < $querylimit)
				{
					$contact = $this->_ParseVCardToAS ( $xml_vcard->vcard->__toString () );
					if ($contact === false)
					{
						ZLog::Write ( LOGLEVEL_ERROR, sprintf ( "BackendCardDAV_OC5->GetGALSearchResults : error converting vCard to AS contact\n%s\n", $xml_vcard->vcard->__toString () ) );
					}
					else
					{
						$items [$rc] [SYNC_GAL_EMAILADDRESS] = $contact->email1address;
						if (isset ( $contact->fileas ))
						{
							$items [$rc] [SYNC_GAL_DISPLAYNAME] = $contact->fileas;
						}
						else if (isset ( $contact->firstname ) || isset ( $contact->middlename ) || isset ( $contact->lastname ))
						{
							$items [$rc] [SYNC_GAL_DISPLAYNAME] = $contact->firstname . (isset ( $contact->middlename ) ? " " . $contact->middlename : "") . (isset ( $contact->lastname ) ? " " . $contact->lastname : "");
						}
						else
						{
							$items [$rc] [SYNC_GAL_DISPLAYNAME] = $contact->email1address;
						}
						
						if (isset ( $contact->firstname ))
						{
							$items [$rc] [SYNC_GAL_FIRSTNAME] = $contact->firstname;
						}
						else
						{
							$items [$rc] [SYNC_GAL_FIRSTNAME] = "";
						}
						
						if (isset ( $contact->lastname ))
						{
							$items [$rc] [SYNC_GAL_LASTNAME] = $contact->lastname;
						}
						else
						{
							$items [$rc] [SYNC_GAL_LASTNAME] = "";
						}
						
						if (isset ( $contact->business2phonenumber ))
						{
							$items [$rc] [SYNC_GAL_PHONE] = $contact->business2phonenumber;
						}
						
						if (isset ( $contact->home2phonenumber ))
						{
							$items [$rc] [SYNC_GAL_HOMEPHONE] = $contact->home2phonenumber;
						}
						
						if (isset ( $contact->mobilephonenumber ))
						{
							$items [$rc] [SYNC_GAL_MOBILEPHONE] = $contact->mobilephonenumber;
						}
						
						if (isset ( $contact->title ))
						{
							$items [$rc] [SYNC_GAL_TITLE] = $contact->title;
						}
						if (isset ( $contact->companyname )) {
							$items [$rc] [SYNC_GAL_COMPANY] = $contact->companyname;
						}
						
						if (isset ( $contact->department ))
						{
							$items [$rc] [SYNC_GAL_OFFICE] = $contact->department;
						}
						
						if (isset ( $contact->nickname ))
						{
							$items [$rc] [SYNC_GAL_ALIAS] = $contact->nickname;
						}
						
						unset ( $contact );
						$rc ++;
					}
				}
				$i ++;
			}
			
			unset ( $xml_vcards );
			return $items;
		}
		else
		{
			unset ( $xml_vcards );
			return false;
		}
	}
	
	/**
	 * Searches for the emails on the server
	 *
	 * @param ContentParameter $cpo        	
	 *
	 * @return array
	 */
	public function GetMailboxSearchResults($cpo)
	{
		return false;
	}
	
	/**
	 * Terminates a search for a given PID
	 *
	 * @param int $pid        	
	 *
	 * @return boolean
	 */
	public function TerminateSearch($pid)
	{
		return true;
	}
}

?>
