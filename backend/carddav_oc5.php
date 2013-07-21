<?php
/***********************************************
* File      :   carddav.php
* Project   :   Z-Push
* Descr     :   This backend is for carddav servers.
*
* Created   :   16.03.2013
*
* Copyright 2007 - 2013 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

// config file

include_once('lib/default/diffbackend/diffbackend.php');
include_once('include/carddav_OC5.php');
include_once('include/z_RTF.php');
include_once('include/vCard.php');

class BackendCardDAV_OC5 extends BackendDiff implements ISearchProvider {

    private $domain = '';
    private $username = '';
    private $url = null;
    private $server = null;

    // Android only supports synchronizing 1 AddressBook per account
    private $foldername = "contacts";
    
    private $changessinkinit = false;
    private $contactsetag;

    /**
     * Constructor
     *
     */
    public function BackendCardDAV_OC5() {
        if (!function_exists("curl_init")) {
            throw new FatalException("BackendCardDAV_OC5(): php-curl is not found", 0, null, LOGLEVEL_FATAL);
        }

        $this->contactsetag = array();
    }
    
    /**
     * Authenticates the user - NOT EFFECTIVELY IMPLEMENTED
     * Normally some kind of password check would be done here.
     * Alternatively, the password could be ignored and an Apache
     * authentication via mod_auth_* could be done
     *
     * @param string        $username
     * @param string        $domain
     * @param string        $password
     *
     * @access public
     * @return boolean
     */
    public function Logon($username, $domain, $password) {
        $url = CARDDAV_PROTOCOL_OC5 . '://' . CARDDAV_SERVER_OC5 . ':' . CARDDAV_PORT_OC5 . str_replace("%d", $domain, str_replace("%u", $username, CARDDAV_PATH_OC5));
        $this->server = new carddav_backend($url);
        $this->server->set_auth($username, $password);
        
        if (($connected = $this->server->check_connection())) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->Logon(): User '%s' is authenticated on '%s'", $username, $url));
            $this->url = $url;
            $this->username = $username;
            $this->domain = $domain;
            $this->server->set_folder(CARDDAV_CONTACTS_FOLDER_NAME_OC5);
        }
        else {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->Logon(): User '%s' failed to authenticate on '%s': %s", $username, $url));
            $this->server = null;
            //TODO: get error message
        }
        
        return $connected;
    }

    /**
     * Logs off
     *
     * @access public
     * @return boolean
     */
    public function Logoff() {
        $this->server = null;
        
        $this->SaveStorages();
        
        unset($this->contactsetag);
        
        return true;
    }

    /**
     * Sends an e-mail
     * Not implemented here
     *
     * @param SyncSendMail  $sm     SyncSendMail object
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function SendMail($sm) {
        return false;
    }

    /**
     * Returns the waste basket
     * Not implemented here
     *
     * @access public
     * @return string
     */
    public function GetWasteBasket() {
        return false;
    }

    /**
     * Returns the content of the named attachment as stream
     * Not implemented here
     *
     * @param string        $attname
     *
     * @access public
     * @return SyncItemOperationsAttachment
     * @throws StatusException
     */
    public function GetAttachmentData($attname) {
        return false;
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
     * @param string        $folderid
     *
     * @access public
     * @return boolean      false if found can not be found
     */
    public function ChangesSinkInitialize($folderid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->ChangesSinkInitialize(): folderid '%s'", $folderid));
        
        $this->changessinkinit = true;

        // We don't need the actual cards, we only need to get the changes since this moment
        //FIXME: we need to get the changes since the last actual sync
        
        $vcards = false;
        try {
            $vcards = $this->server->do_sync(true, false);
        }
        catch (Exception $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->ChangesSinkInitialize - Error doing the initial sync: %s", $ex->getMessage()));
        }
        
        if ($vcards === false) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->ChangesSinkInitialize - Error initializing the sink"));
            return false;
        }
        
        unset($vcards);
        
        return true;
    }

    /**
     * The actual ChangesSink.
     * For max. the $timeout value this method should block and if no changes
     * are available return an empty array.
     * If changes are available a list of folderids is expected.
     *
     * @param int           $timeout        max. amount of seconds to block
     *
     * @access public
     * @return array
     */
    public function ChangesSink($timeout = 30) {
        $notifications = array();
        $stopat = time() + $timeout - 1;
        $changed = false;

        //We can get here and the ChangesSink not be initialized yet
        if (!$this->changessinkinit) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->ChangesSink - Not initialized ChangesSink, exiting"));
            return $notifications;
        }
        
        while($stopat > time() && empty($notifications)) {
            $vcards = false;
            try {
                $vcards = $this->server->do_sync(false, false);
            }
            catch (Exception $ex) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->ChangesSink - Error resyncing vcards: %s", $ex->getMessage()));
            }
            
            if ($vcards === false) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->ChangesSink - Error getting the changes"));
                return false;
            }
            else {
                $xml_vcards = new SimpleXMLElement($vcards);
                unset($vcards);
                
                if (count($xml_vcards->element) > 0) {
                    $changed = true;
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->ChangesSink - Changes detected"));
                }
                unset($xml_vcards);
            }
            
            if ($changed) {
                $notifications[] = $this->foldername;
            }

            if (empty($notifications))
                sleep(5);
        }

        return $notifications;
    }    

    /**----------------------------------------------------------------------------------------------------------
     * implemented DiffBackend methods
     */

    /**
     * Returns a list (array) of folders.
     * In simple implementations like this one, probably just one folder is returned.
     *
     * @access public
     * @return array
     */
    public function GetFolderList() {
        ZLog::Write(LOGLEVEL_DEBUG, 'BackendCardDAV_OC5::GetFolderList()');
        
        //TODO: support multiple addressbooks, autodiscover thems
        $addressbooks = array();
        $addressbook = $this->StatFolder($this->foldername);
        $addressbooks[] = $addressbook;

        return $addressbooks;
    }

    /**
     * Returns an actual SyncFolder object
     *
     * @param string        $id           id of the folder
     *
     * @access public
     * @return object       SyncFolder with information
     */
    public function GetFolder($id) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5::GetFolder('%s')", $id));
        
        $addressbook = false;
        
        if ($id == $this->foldername) {
            $addressbook = new SyncFolder();
            $addressbook->serverid = $id;
            $addressbook->parentid = "0";
            $addressbook->displayname = str_replace("%d", $this->domain, str_replace("%u", $this->username, CARDDAV_CONTACTS_FOLDER_NAME_OC5));
            $addressbook->type = SYNC_FOLDER_TYPE_CONTACT;
        }

        return $addressbook;
    }

    /**
     * Returns folder stats. An associative array with properties is expected.
     *
     * @param string        $id             id of the folder
     *
     * @access public
     * @return array
     */
    public function StatFolder($id) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5::StatFolder('%s')", $id));
        
        $addressbook = $this->GetFolder($id);

        $stat = array();
        $stat["id"] = $id;
        $stat["parent"] = $addressbook->parentid;
        $stat["mod"] = $addressbook->displayname;

        return $stat;
    }

    /**
     * Creates or modifies a folder
     * Not implemented here
     *
     * @param string        $folderid       id of the parent folder
     * @param string        $oldid          if empty -> new folder created, else folder is to be renamed
     * @param string        $displayname    new folder name (to be created, or to be renamed to)
     * @param int           $type           folder type
     *
     * @access public
     * @return boolean                      status
     * @throws StatusException              could throw specific SYNC_FSSTATUS_* exceptions
     *
     */
    public function ChangeFolder($folderid, $oldid, $displayname, $type){
        return false;
    }

    /**
     * Deletes a folder
     * Not implemented here
     *
     * @param string        $id
     * @param string        $parent         is normally false
     *
     * @access public
     * @return boolean                      status - false if e.g. does not exist
     * @throws StatusException              could throw specific SYNC_FSSTATUS_* exceptions
     *
     */
    public function DeleteFolder($id, $parentid){
        return false;
    }

    /**
     * Returns a list (array) of messages
     *
     * @param string        $folderid       id of the parent folder
     * @param long          $cutoffdate     timestamp in the past from which on messages should be returned
     *
     * @access public
     * @return array/false  array with messages or false if folder is not available
     */
    public function GetMessageList($folderid, $cutoffdate) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->GetMessageList('%s', '%s')", $folderid, $cutoffdate));
        
        $messages = array();
        
        $vcards = false;
        try {
            // We don't need the actual vcards here, we only need a list of all them
            //$vcards = $this->server->get_list_vcards();
            $vcards = $this->server->do_sync(true, false);
        }
        catch (Exception $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->GetMessageList - Error getting the vcards: %s", $ex->getMessage()));
        }
        
        if ($vcards === false) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->GetMessageList - Error getting the vcards"));
        }
        else {
            $xml_vcards = new SimpleXMLElement($vcards);
            foreach ($xml_vcards->element as $vcard) {
                $id = $vcard->id->__toString();
                $this->contactsetag[$id] = $vcard->etag->__toString();
                $messages[] = $this->StatMessage($folderid, $id);
            }
        }

        return $messages;
    }

    /**
     * Returns the actual SyncXXX object type.
     *
     * @param string            $folderid           id of the parent folder
     * @param string            $id                 id of the message
     * @param ContentParameters $contentparameters  parameters of the requested message (truncation, mimesupport etc)
     *
     * @access public
     * @return object/false     false if the message could not be retrieved
     */
    public function GetMessage($folderid, $id, $contentparameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->GetMessage('%s', '%s')", $folderid, $id));
        
        $message = false;
        
        //TODO: change folderid
        $xml_vcard = false;
        try {
            $xml_vcard = $this->server->get_xml_vcard($id);
        }
        catch (Exception $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->GetMessage - Error getting vcard: %s", $ex->getMessage()));
        }
        
        if ($xml_vcard === false) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->GetMessage(): getting vCard"));
        }
        else {
            $truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());
            $xml_data = new SimpleXMLElement($xml_vcard);
            $message = $this->ParseFromVCard($xml_data->element[0]->vcard->__toString(), $truncsize);
        }
        
        return $message;
    }
    

    /**
     * Returns message stats, analogous to the folder stats from StatFolder().
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     *
     * @access public
     * @return array
     */
    public function StatMessage($folderid, $id) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->StatMessage('%s', '%s')", $folderid, $id));
//TODO: change to folderid
//new from here
       	try {
            // We don't need the actual vcards here, we only need a list of all them
            //$vcards = $this->server->get_list_vcards();
            	$vcards = $this->server->do_sync(true, false);
        	}
        	catch (Exception $ex) {
            	ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->GetMessageList - Error getting the vcards: %s", $ex->getMessage()));
        	}
        
        	if ($vcards === false) {
            		ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->GetMessageList - Error getting the vcards"));
        	}
		else {
			$xml_vcards = new SimpleXMLElement($vcards);
	     		foreach ($xml_vcards->element as $vcard) {
                		$id_card = $vcard->id->__toString();
                		$this->contactsetag[$id_card] = $vcard->etag->__toString();
            			}
		}
	if (empty($this->contactsetag[$id])){
		return false;
	}
// new end
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->StatMessage('%s', '%s')", $this->contactsetag[$id], $id));
        $message = array();
        $message["mod"] = $this->contactsetag[$id];
        $message["id"] = $id;
        $message["flags"] = 1;
        $message["star"] = 0;
        return $message;
    }

    /**
     * Called when a message has been changed on the mobile.
     * This functionality is not available for emails.
     *
     * @param string              $folderid            id of the folder
     * @param string              $id                  id of the message
     * @param SyncXXX             $message             the SyncObject containing a message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return array                        same return value as StatMessage()
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function ChangeMessage($folderid, $id, $message) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->ChangeMessage('%s', '%s')", $folderid, $id));

        $vcard_text = $this->ParseToVCard($message);
        
        if ($vcard_text === false) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->ChangeMessage - Error converting message to vCard"));
        }
        else {
            ZLog::Write(LOGLEVEL_WBXML, sprintf("BackendCardDAV_OC5->ChangeMessage - vCard\n%s\n", $vcard_text));
            
            $updated = false;
            if (strlen($id) == 0) {
                //no id, new vcard
                try {
                    $updated = $this->server->add($vcard_text);
                    if ($updated !== false) {
                        $id = $updated;
                    }
                }
                catch (Exception $ex) {
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->ChangeMessage - Error adding vcard '%s' : %s", $id, $ex->getMessage()));
                }
            }
            else {
                //id, update vcard
                try {
                    $updated = $this->server->update($vcard_text, $id);
                }
                catch (Exception $ex) {
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->ChangeMessage - Error updating vcard '%s' : %s", $id, $ex->getMessage()));
                }
            }
            
            if ($updated !== false) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->ChangeMessage - vCard updated"));
            }
            else {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->ChangeMessage - vCard not updated"));
            }
        }
        
        return $this->StatMessage($folderid, $id);
    }   

    /**
     * Changes the 'read' flag of a message on disk
     * Not implemented here
     *
     * @param string              $folderid            id of the folder
     * @param string              $id                  id of the message
     * @param int                 $flags               read flag of the message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function SetReadFlag($folderid, $id, $flags) {
        return false;
    }

    /**
     * Changes the 'star' flag of a message on disk
     * Not implemented here
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     * @param int           $flags          star flag of the message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function SetStarFlag($folderid, $id, $flags, $contentParameters) {
        return false;
    }

    /**
     * Called when the user has requested to delete (really delete) a message
     *
     * @param string              $folderid             id of the folder
     * @param string              $id                   id of the message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function DeleteMessage($folderid, $id) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->DeleteMessage('%s', '%s')", $folderid, $id));
        
        $deleted = false;
        try {
            $deleted = $this->server->delete($id);
        }
        catch (Exception $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->DeleteMessage - Error deleting vcard: %s", $ex->getMessage()));
        }
        
        if ($deleted) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->DeleteMessage - vCard deleted"));
        } 
        else {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->DeleteMessage - cannot delete vCard"));
        }
        
        return $deleted;
    }

    /**
     * Called when the user moves an item on the PDA from one folder to another
     * Not implemented here
     *
     * @param string              $folderid            id of the source folder
     * @param string              $id                  id of the message
     * @param string              $newfolderid         id of the destination folder
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_MOVEITEMSSTATUS_* exceptions
     */
    public function MoveMessage($folderid, $id, $newfolderid) {
        return false;
    }

    
    /**
     * Indicates which AS version is supported by the backend.
     *
     * @access public
     * @return string       AS version constant
     */
    public function GetSupportedASVersion() {
        return ZPush::ASV_14;
    }


    /**
     * Returns the BackendCardDAV_OC5 as it implements the ISearchProvider interface
     * This could be overwritten by the global configuration
     *
     * @access public
     * @return object       Implementation of ISearchProvider
     */
    public function GetSearchProvider() {
        return $this;
    }


    /**----------------------------------------------------------------------------------------------------------
     * public ISearchProvider methods
     */

    /**
     * Indicates if a search type is supported by this SearchProvider
     * Currently only the type ISearchProvider::SEARCH_GAL (Global Address List) is implemented
     *
     * @param string        $searchtype
     *
     * @access public
     * @return boolean
     */
    public function SupportsType($searchtype) {
        return ($searchtype == ISearchProvider::SEARCH_GAL);
    }


    /**
     * Queries the CardDAV backend
     *
     * @param string        $searchquery        string to be searched for
     * @param string        $searchrange        specified searchrange
     *
     * @access public
     * @return array        search results
     */
    public function GetGALSearchResults($searchquery, $searchrange) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->GetGALSearchResults(%s, %s)", $searchquery, $searchrange));
        if (isset($this->server) && $this->server !== false) {
            if (strlen($searchquery) < 5) {
                return false;
            }

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->GetGALSearchResults searching: %s", $this->url));
            try {
                $this->server->enable_debug();
                $vcards = $this->server->search_vcards(str_replace("<", "", str_replace(">", "", $searchquery)), 15, true, false);
            }
            catch (Exception $e) {
                $vcards = false;
                ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->GetGALSearchResults : Error in search %s", $e->getMessage()));
            }
            if ($vcards === false) {
                ZLog::Write(LOGLEVEL_ERROR, "BackendCardDAV_OC5->GetGALSearchResults : Error in search query. Search aborted");
                return false;
            }
            
            $xml_vcards = new SimpleXMLElement($vcards);
            unset($vcards);
            
            // range for the search results, default symbian range end is 50, wm 99,
            // so we'll use that of nokia
            $rangestart = 0;
            $rangeend = 50;

            if ($searchrange != '0') {
                $pos = strpos($searchrange, '-');
                $rangestart = substr($searchrange, 0, $pos);
                $rangeend = substr($searchrange, ($pos + 1));
            }
            $items = array();

            // TODO the limiting of the searchresults could be refactored into Utils as it's probably used more than once
            $querycnt = $xml_vcards->count();
            //do not return more results as requested in range
            $querylimit = (($rangeend + 1) < $querycnt) ? ($rangeend + 1) : $querycnt == 0 ? 1 : $querycnt;
            $items['range'] = $rangestart.'-'.($querylimit - 1);
            $items['searchtotal'] = $querycnt;
            
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendCardDAV_OC5->GetGALSearchResults : %s entries found, returning %s to %s", $querycnt, $rangestart, $querylimit));
            
            $i = 0;
            $rc = 0;
            foreach ($xml_vcards->element as $xml_vcard) {
                if ($i >= $rangestart && $i < $querylimit) {
                    $contact = $this->ParseFromVCard($xml_vcard->vcard->__toString());
                    if ($contact === false) {
                        ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendCardDAV_OC5->GetGALSearchResults : error converting vCard to AS contact\n%s\n", $xml_vcard->vcard->__toString()));
                    }
                    else {
                        $items[$rc][SYNC_GAL_EMAILADDRESS] = $contact->email1address;
                        if (isset($contact->fileas)) {
                            $items[$rc][SYNC_GAL_DISPLAYNAME] = $contact->fileas;
                        } 
                        else if (isset($contact->firstname) || isset($contact->middlename) || isset($contact->lastname)) {
                            $items[$rc][SYNC_GAL_DISPLAYNAME] = $contact->firstname . (isset($contact->middlename) ? " " . $contact->middlename : "") . (isset($contact->lastname) ? " " . $contact->lastname : "");
                        }
                        else {
                            $items[$rc][SYNC_GAL_DISPLAYNAME] = $contact->email1address;
                        }
                        if (isset($contact->firstname)) {
                            $items[$rc][SYNC_GAL_FIRSTNAME] = $contact->firstname;
                        }
                        else {
                            $items[$rc][SYNC_GAL_FIRSTNAME] = "";
                        }
                        if (isset($contact->lastname)) {
                            $items[$rc][SYNC_GAL_LASTNAME] = $contact->lastname;
                        }
                        else {
                            $items[$rc][SYNC_GAL_LASTNAME] = "";
                        }
                        if (isset($contact->business2phonenumber)) {
                            $items[$rc][SYNC_GAL_PHONE] = $contact->business2phonenumber;
                        }
                        if (isset($contact->home2phonenumber)) {
                            $items[$rc][SYNC_GAL_HOMEPHONE] = $contact->home2phonenumber;
                        }
                        if (isset($contact->mobilephonenumber)) {
                            $items[$rc][SYNC_GAL_MOBILEPHONE] = $contact->mobilephonenumber;
                        }
                        if (isset($contact->title)) {
                            $items[$rc][SYNC_GAL_TITLE] = $contact->title;
                        }
                        if (isset($contact->companyname)) {
                            $items[$rc][SYNC_GAL_COMPANY] = $contact->companyname;
                        }
                        if (isset($contact->department)) {
                            $items[$rc][SYNC_GAL_OFFICE] = $contact->department;
                        }
                        if (isset($contact->nickname)) {
                            $items[$rc][SYNC_GAL_ALIAS] = $contact->nickname;
                        }
                        unset($contact);
                        $rc++;
                    }
                }
                $i++;
            }
            
            unset($xml_vcards);
            return $items;
        }
        else {
            unset($xml_vcards);
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
    public function GetMailboxSearchResults($cpo) {
        return false;
    }

    /**
    * Terminates a search for a given PID
    *
    * @param int $pid
    *
    * @return boolean
    */
    public function TerminateSearch($pid) {
        return true;
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
    

    /**----------------------------------------------------------------------------------------------------------
     * private vcard-specific internals
     */


    /**
     * Escapes a string
     *
     * @param string        $data           string to be escaped
     *
     * @access private
     * @return string
     */
    private function escape($data){
        if (is_array($data)) {
            foreach ($data as $key => $val) {
                $data[$key] = $this->escape($val);
            }
            return $data;
        }
        $data = str_replace("\r\n", "\n", $data);
        $data = str_replace("\r", "\n", $data);
        $data = str_replace(array('\\', ';', ',', "\n"), array('\\\\', '\\;', '\\,', '\\n'), $data);
        return $data;
    }

    /**
     * Un-escapes a string
     *
     * @param string        $data           string to be un-escaped
     *
     * @access private
     * @return string
     */
    private function unescape($data){
        $data = str_replace(array('\\\\', '\\;', '\\,', '\\n','\\N'),array('\\', ';', ',', "\n", "\n"),$data);
        return $data;
    }
    
    /**
     * Converts the vCard into SyncContact
     *
     * @param string        $data           string with the vcard
     * @param int           $truncsize      truncate size requested
     * @return SyncContact
     */
    private function ParseFromVCard($data, $truncsize = -1) {
        ZLog::Write(LOGLEVEL_WBXML, sprintf("BackendCardDAV_OC5->ParseFromVCard : vCard\n%s\n", $data));
        
        $types = array ('dom' => 'type', 'intl' => 'type', 'postal' => 'type', 'parcel' => 'type', 'home' => 'type', 'work' => 'type',
            'pref' => 'type', 'voice' => 'type', 'fax' => 'type', 'msg' => 'type', 'cell' => 'type', 'pager' => 'type',
            'bbs' => 'type', 'modem' => 'type', 'car' => 'type', 'isdn' => 'type', 'video' => 'type',
            'aol' => 'type', 'applelink' => 'type', 'attmail' => 'type', 'cis' => 'type', 'eworld' => 'type',
            'internet' => 'type', 'ibmmail' => 'type', 'mcimail' => 'type',
            'powershare' => 'type', 'prodigy' => 'type', 'tlx' => 'type', 'x400' => 'type',
            'gif' => 'type', 'cgm' => 'type', 'wmf' => 'type', 'bmp' => 'type', 'met' => 'type', 'pmb' => 'type', 'dib' => 'type',
            'pict' => 'type', 'tiff' => 'type', 'pdf' => 'type', 'ps' => 'type', 'jpeg' => 'type', 'qtime' => 'type',
            'mpeg' => 'type', 'mpeg2' => 'type', 'avi' => 'type',
            'wave' => 'type', 'aiff' => 'type', 'pcm' => 'type',
            'x509' => 'type', 'pgp' => 'type', 'text' => 'value', 'inline' => 'value', 'url' => 'value', 'cid' => 'value', 'content-id' => 'value',
            '7bit' => 'encoding', '8bit' => 'encoding', 'quoted-printable' => 'encoding', 'base64' => 'encoding',
        );

        // Parse the vcard
        $message = new SyncContact();

        $data = str_replace("\x00", '', $data);
        $data = str_replace("\r\n", "\n", $data);
        $data = str_replace("\r", "\n", $data);
        $data = preg_replace('/(\n)([ \t])/i', '', $data);

        $lines = explode("\n", $data);

        $vcard = array();
        foreach($lines as $line) {
            if (trim($line) == '')
                continue;
            $pos = strpos($line, ':');
            if ($pos === false)
                continue;

            $field = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos+1));

            $fieldparts = preg_split('/(?<!\\\\)(\;)/i', $field, -1, PREG_SPLIT_NO_EMPTY);

            $type = strtolower(array_shift($fieldparts));

            $fieldvalue = array();

            foreach ($fieldparts as $fieldpart) {
                if(preg_match('/([^=]+)=(.+)/', $fieldpart, $matches)){
                    if(!in_array(strtolower($matches[1]),array('value','type','encoding','language')))
                        continue;
                    if(isset($fieldvalue[strtolower($matches[1])]) && is_array($fieldvalue[strtolower($matches[1])])){
                        $fieldvalue[strtolower($matches[1])] = array_merge($fieldvalue[strtolower($matches[1])], preg_split('/(?<!\\\\)(\,)/i', $matches[2], -1, PREG_SPLIT_NO_EMPTY));
                    }else{
                        $fieldvalue[strtolower($matches[1])] = preg_split('/(?<!\\\\)(\,)/i', $matches[2], -1, PREG_SPLIT_NO_EMPTY);
                    }
                }else{
                    if(!isset($types[strtolower($fieldpart)]))
                        continue;
                    $fieldvalue[$types[strtolower($fieldpart)]][] = $fieldpart;
                }
            }
            //
            switch ($type) {
                case 'categories':
                     $val = preg_split('/(?<!\\\\)(\,)/i', $value);
                    break;
                default:
                    $val = preg_split('/(?<!\\\\)(\;)/i', $value);
                    break;
            }
            if(isset($fieldvalue['encoding'][0])){
                switch(strtolower($fieldvalue['encoding'][0])){
                    case 'q':
                    case 'quoted-printable':
                        foreach($val as $i => $v){
                            $val[$i] = quoted_printable_decode($v);
                        }
                        break;
                    case 'b':
                    case 'base64':
                        foreach($val as $i => $v){
                            $val[$i] = base64_decode($v);
                        }
                        break;
                }
            }else{
                foreach($val as $i => $v){
                    $val[$i] = $this->unescape($v);
                }
            }
            $fieldvalue['val'] = $val;
            $vcard[$type][] = $fieldvalue;
        }

        if(isset($vcard['email'])){
		foreach($vcard['email'] as $mail){
			if (!empty($mail['val'][0])){
				If (empty($message->email1address)){
					$message->email1address = $mail['val'][0];
				}elseif (empty($message->email2address)){
					$message->email2address = $mail['val'][0];
				}elseif (empty($message->email3address)){
					$message->email3address = $mail['val'][0];
				}elseif((strpos($vcard['note'][0]['val'][0],$mail['val'][0])) === false){
					$vcard['note'][0]['val'][0] = 'EMAIL#TYPE=' . $mail['type'][0] . ':' . $mail['val'][0] . chr(13) . chr(10) . $vcard['note'][0]['val'][0];
				}
			}
		}
	}
        if(isset($vcard['impp'])){
		foreach($vcard['impp'] as $impp){
			if (!empty($impp['val'][0])){
				If (empty($message->imaddress)){
					$message->imaddress = $impp['val'][0];
				}elseif (empty($message->imaddress2)){
					$message->imaddress2 = $impp['val'][0];
				}elseif (empty($message->imaddress3)){
					$message->imaddress3 = $impp['val'][0];
				}elseif((strpos($vcard['note'][0]['val'][0],$impp['val'][0])) === false){
					$vcard['note'][0]['val'][0] = 'IMPP#TYPE=' . $impp['type'][0] . ':' . $impp['val'][0] . chr(13) . chr(10) . $vcard['note'][0]['val'][0];
				}
			}
		}
	}
		

/*
	 if(isset($vcard['email'][0]['val'][0]))
            $message->email1address = $vcard['email'][0]['val'][0];
        if(isset($vcard['email'][1]['val'][0]))
            $message->email2address = $vcard['email'][1]['val'][0];
        if(isset($vcard['email'][2]['val'][0]))
            $message->email3address = $vcard['email'][2]['val'][0];
*/

        if(isset($vcard['tel'])){
            foreach($vcard['tel'] as $tel) {
                if(is_array($tel['type'])){
	              if(in_array('HOME', $tel['type'])){
               		if(empty($message->homephonenumber)){
                        		$message->homephonenumber = $tel['val'][0];
                    		}elseif(empty($message->home2phonenumber)){
					if(in_array('PREF', $tel['type'])){
						$message->home2phonenumber = $message->homephonenumber;					
						$message->homephonenumber = $tel['val'][0];
					}else{
	                        		$message->home2phonenumber = $tel['val'][0];
					}
                    		}elseif((strpos($vcard['note'][0]['val'][0],$tel['val'][0])) === false){
					$vcard['note'][0]['val'][0] = 'TEL#TYPE=HOME;TYPE=OTHER:' . $tel['val'][0] . chr(13) . chr(10) . $vcard['note'][0]['val'][0];
				}
                	 }elseif(in_array('CAR', $tel['type'])){
                    			if(empty($message->carphonenumber)){
						$message->carphonenumber = $tel['val'][0];
					}
					elseif((strpos($vcard['note'][0]['val'][0],$tel['val'][0])) === false){
						$vcard['note'][0]['val'][0] = 'TEL#TYPE=CAR#TYPE=OTHER:' . $tel['val'][0] . chr(13) . chr(10) . $vcard['note'][0]['val'][0];
					}
                	}elseif(in_array('PAGER', $tel['type'])){
				if(empty($message->pagernumber)){
	                    		$message->pagernumber = $tel['val'][0];
				}
				elseif((strpos($vcard['note'][0]['val'][0],$tel['val'][0])) === false){
					$vcard['note'][0]['val'][0] = 'TEL#TYPE=PAGER#TYPE=OTHER:' . $tel['val'][0] . chr(13) . chr(10) . $vcard['note'][0]['val'][0];
				}
                	}elseif(in_array('CELL', $tel['type'])){
				if(empty($message->mobilephonenumber)){
                    			$message->mobilephonenumber = $tel['val'][0];
				}elseif((strpos($vcard['note'][0]['val'][0],$tel['val'][0]) === false)){
					$vcard['note'][0]['val'][0] = 'TEL#TYPE=CELL#TYPE=OTHER:' . $tel['val'][0] . chr(13) . chr(10) . $vcard['note'][0]['val'][0];
				}	
                	}elseif(in_array('WORK', $tel['type'])){
                    		if(empty($message->businessphonenumber)){
                        		$message->businessphonenumber = $tel['val'][0];
                    		}elseif(empty($message->business2phonenumber)){
					if(in_array('PREF', $tel['type'])){
						$message->business2phonenumber = $message->businessphonenumber;					
						$message->businessphonenumber = $tel['val'][0];
					}else{
                        			$message->business2phonenumber = $tel['val'][0];
					}
                    		}elseif(empty($message->companymainphone)){
                   			$message->companymainphone = $tel['val'][0];
               		}elseif((strpos($vcard['note'][0]['val'][0],$tel['val'][0])) === false){
					$vcard['note'][0]['val'][0] = 'TEL#TYPE=WORK#TYPE=OTHER:' . $tel['val'][0] . chr(13) . chr(10) . $vcard['note'][0]['val'][0];
				}	
			}elseif(in_array('FAX', $tel['type'])){
				if(in_array('HOME', $tel['type'])){
                        		if(empty($message->homefaxnumber)){
						$message->homefaxnumber = $tel['val'][0];
					}
					elseif((strpos($vcard['note'][0]['val'][0],$tel['val'][0])) === false){
						$vcard['note'][0]['val'][0] = 'TEL#TYPE=FAX#TYPE=HOME#TYPE=OTHER:' . $tel['val'][0] . chr(13) . chr(10) . $vcard['note'][0]['val'][0];
					}
				}elseif(in_array('WORK', $tel['type'])){
	                    		if(empty($message->businessfaxnumber)){
       	                 		$message->businessfaxnumber = $tel['val'][0];
                    			}elseif((strpos($vcard['note'][0]['val'][0],$tel['val'][0])) === false){
						$vcard['note'][0]['val'][0] = 'TEL#TYPE=FAX#TYPE=WORK#TYPE=OTHER:' . $tel['val'][0] . chr(13) . chr(10) . $vcard['note'][0]['val'][0];
					}
				}else{
					if(empty($message->businessfaxnumber)){
						$message->businessfaxnumber = $tel['val'][0];
					}elseif(empty($message->homefaxnumber)){
						$message->homefaxnumber = $tel['val'][0];
                    			}elseif((strpos($vcard['note'][0]['val'][0],$tel['val'][0])) === false){
						$vcard['note'][0]['val'][0] = 'TEL#TYPE=FAX#TYPE=OTHER:' . $tel['val'][0] . chr(13) . chr(10) . $vcard['note'][0]['val'][0];
					}
				}
			}elseif(in_array('TEXT', $tel['type'])){
				if(empty($message->mms)){
       	                 		$message->mms = $tel['val'][0];
                    			}elseif((strpos($vcard['note'][0]['val'][0],$tel['val'][0])) === false){
						$vcard['note'][0]['val'][0] = 'TEL#TYPE=TEXT#TYPE=OTHER:'. $tel['val'][0] . chr(13) . chr(10) . $vcard['note'][0]['val'][0];
					}
			}elseif(in_array('OTHER', $tel['type'])){
				if(empty($message->radiophonenumber)){
       	                 		$message->radiophonenumber = $tel['val'][0];
                    			}elseif((strpos($vcard['note'][0]['val'][0],$tel['val'][0])) === false){
						$vcard['note'][0]['val'][0] = 'TEL#TYPE=OTHER#TYPE=OTHER:'. $tel['val'][0] . chr(13) . chr(10) . $vcard['note'][0]['val'][0];
					}
			}elseif(in_array('MSG', $tel['type'])){
				if(empty($message->companymainphone)){
       	                 		$message->companymainphone = $tel['val'][0];
                    			}elseif((strpos($vcard['note'][0]['val'][0],$tel['val'][0])) === false){
						$vcard['note'][0]['val'][0] = 'TEL#TYPE=MSG#TYPE=OTHER:'. $tel['val'][0] . chr(13) . chr(10) . $vcard['note'][0]['val'][0];
					}
			}elseif(in_array('VOICE', $tel['type'])){
					if(empty($message->assistnamephonenumber)){
       	                 		$message->assistnamephonenumber = $tel['val'][0];
 					
                    			}elseif((strpos($vcard['note'][0]['val'][0],$tel['val'][0])) === false){
						$vcard['note'][0]['val'][0] = 'TEL#TYPE=VOICE#TYPE=OTHER:'. $tel['val'][0] . chr(13) . chr(10) . $vcard['note'][0]['val'][0];
					}
			
		   	}elseif(strpos($vcard['note'][0]['val'][0],$tel['val'][0]) === false){
			$vcard['note'][0]['val'][0] = 'TEL:'. $tel['val'][0] . chr(13) . chr(10) . $vcard['note'][0]['val'][0];
		   	}
            	}
		}
        }
        //;;street;city;state;postalcode;country
        if(isset($vcard['adr'])){
            foreach($vcard['adr'] as $adr) {
                if(empty($adr['type'])){
                    $a = 'other';
                }elseif(in_array('HOME', $adr['type'])){
                    $a = 'home';
                }elseif(in_array('WORK', $adr['type'])){
                    $a = 'business';
                }else{
                    $a = 'other';
                }
                if(!empty($adr['val'][2])){
                    $b=$a.'street';
                    $message->$b = $adr['val'][2];
                }
                if(!empty($adr['val'][3])){
                    $b=$a.'city';
                    $message->$b = $adr['val'][3];
                }
                if(!empty($adr['val'][4])){
                    $b=$a.'state';
                    $message->$b = $adr['val'][4];
                }
                if(!empty($adr['val'][5])){
                    $b=$a.'postalcode';
                    $message->$b = $adr['val'][5];
                }
                if(!empty($adr['val'][6])){
                    $b=$a.'country';
                    $message->$b = $adr['val'][6];
                }
            }
        }

        if(!empty($vcard['fn'][0]['val'][0]))
            $message->fileas = $vcard['fn'][0]['val'][0];
        if(!empty($vcard['n'][0]['val'][0]))
            $message->lastname = $vcard['n'][0]['val'][0];
        if(!empty($vcard['n'][0]['val'][1]))
            $message->firstname = $vcard['n'][0]['val'][1];
        if(!empty($vcard['n'][0]['val'][2]))
            $message->middlename = $vcard['n'][0]['val'][2];
        if(!empty($vcard['n'][0]['val'][3]))
            $message->title = $vcard['n'][0]['val'][3];
        if(!empty($vcard['n'][0]['val'][4]))
            $message->suffix = $vcard['n'][0]['val'][4];
        if(!empty($vcard['nickname'][0]['val'][0]))
            $message->nickname = $vcard['nickname'][0]['val'][0];
        if(!empty($vcard['bday'][0]['val'][0])){
            $tz = date_default_timezone_get();
            date_default_timezone_set('UTC');
            $message->birthday = strtotime($vcard['bday'][0]['val'][0]);
            date_default_timezone_set($tz);
        }
        if(!empty($vcard['org'][0]['val'][0]))
            $message->companyname = $vcard['org'][0]['val'][0];
        if(!empty($vcard['note'][0]['val'][0])){
            if (Request::GetProtocolVersion() >= 12.0) {
                $message->asbody = new SyncBaseBody();
                $message->asbody->type = SYNC_BODYPREFERENCE_PLAIN;
                $message->asbody->data = $vcard['note'][0]['val'][0];
                if ($truncsize > 0 && $truncsize < strlen($message->asbody->data)) {
                    $message->asbody->truncated = 1;
                    $message->asbody->data = Utils::Utf8_truncate($message->asbody->data, $truncsize);
                }
                else {
                    $message->asbody->truncated = 0;
                }
                
                $message->asbody->estimatedDataSize = strlen($message->asbody->data);                
            }
            else {
                $message->body = $vcard['note'][0]['val'][0];
                if ($truncsize > 0 && $truncsize < strlen($message->body)) {
                    $message->bodytruncated = 1;
                    $message->body = Utils::Utf8_truncate($message->body, $truncsize);
                }
                else {
                    $message->bodytruncated = 0;
                }
                $message->bodysize = strlen($message->body);
            }
        }
	 if(!empty($vcard['role'][0]['val'][0]))
            $message->jobtitle = $vcard['role'][0]['val'][0];//$vcard['title'][0]['val'][0]
        if(!empty($vcard['url'][0]['val'][0]))
            $message->webpage = $vcard['url'][0]['val'][0];
        if(!empty($vcard['categories'][0]['val']))
            $message->categories = $vcard['categories'][0]['val'];

        if(!empty($vcard['photo'][0]['val'][0]))
            $message->picture = base64_encode($vcard['photo'][0]['val'][0]);

        return $message;
    }
    
    /**
     * Convert a SyncObject into vCard.
     *
     * @param SyncContact           $message        AS Contact
     * @return string               vcard text
     */
    private function ParseToVCard($message) {  
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
	     'assistnamephonenumber' =>  'TEL;TYPE=VOICE',
            'companyname' => 'ORG',
            'jobtitle' => 'ROLE',
            'webpage' => 'URL',
            'nickname' => 'NICKNAME',
	     'imaddress' => 'IMPP',
	     'imaddress2' => 'IMPP',
	     'imaddress3' => 'IMPP'
        );
// start baking the vcard 
	$data = "BEGIN:VCARD\nVERSION:3.0\nPRODID:Z-Push\n";
// using BuildFileAs($lastname = "", $firstname = "", $middlename = "", $company = "") from utils.php defined in config-php via FILEAS_ORDER
	if (empty($message->fileas) || FILEAS_ALLWAYSOVERRIDE_OC5 === true) {
	 	$data .= 'FN:' . Utils::BuildFileAs($message->lastname, $message->firstname, $message->middlename, $message->company). "\n";
	}
	else{
		$data .= 'FN:' . $message->fileas . "\n";
	}
        foreach($adrmapping as $adrk => $adrv){
            $adrval = null;
            $adrks = explode(';', $adrk);
            foreach($adrks as $adri){
                if((!empty($message->$adri)) && ($messabge->$adri != '')){
                    $adrval .= $this->escape($message->$adri);
		  }
		  $adrval.=';';
            }
            if((empty($adrval)) || (str_replace(';' , '' , $adrval) == '') )
                continue;
            $adrval = substr($adrval,0,-1);
            if(strlen($adrval)>50){
                $data .= $adrv.":\n ". chunk_split($adrval, 50, "\n ");
		  $data .= "\n";
            }else{
                $data .= $adrv.':'.$adrval."\n";
            }
        }
        foreach($mapping as $k => $v){
            $val = null;
            $ks = explode(';', $k);
            foreach($ks as $i){
                if((!empty($message->$i)) && ($message->$i != '')){
                    $val .= $this->escape($message->$i);
                    $val.=';';
		  }
            }
            if(empty($val))
                continue;
            $val = substr($val,0,-1);
            if(strlen($val)>50){
                $data .= $v;
		  $data .= ":\n " . chunk_split($val, 50, "\n ");
		  $data .= "\n";
            }else{
                $data .= $v.':'.$val."\n";
            }
        }
	 if(isset($message->birthday))
            $data .= 'BDAY:'.date('Y-m-d', $message->birthday)."\n";
        if(!empty($message->categories))
            $data .= 'CATEGORIES:'.implode(',', $this->escape($message->categories))."\n";
        if(!empty($message->body))
		$data .= 'NOTE:\n ' . str_replace('\n' , '\n ' , $message->body). "\n "; 
        if(!empty($message->picture)){
     		$data .= 'PHOTO;ENCODING=BASE64;TYPE=JPEG:'."\n ".chunk_split($message->picture, 50, "\n ");
		$data .= "\n"; 
	 }
        $data .= "\nEND:VCARD";


        // http://en.wikipedia.org/wiki/VCard
        // TODO: add support for v4.0
        // not supported: anniversary, assistantname, assistnamephonenumber, children, department, officelocation, radiophonenumber, spouse, rtf

        return $data;
    }
    
};
?>
