<?php
/***********************************************
* File      :   backend/combined/config.php
* Project   :   Z-Push
* Descr     :   configuration file for the
*               combined backend.
*
* Created   :   29.11.2010
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

class BackendCombinedConfig {

    // *************************
    //  BackendIMAP settings
    // *************************
    public static $BackendIMAP_config = array(
        // Defines the server to which we want to connect
        'IMAP_SERVER' => IMAP_SERVER,
        // connecting to default port (143)
        'IMAP_PORT' => IMAP_PORT,
        // best cross-platform compatibility (see http://php.net/imap_open for options)
        'IMAP_OPTIONS' => IMAP_OPTIONS,
        // overwrite the "from" header if it isn't set when sending emails
        // options: 'username'    - the username will be set (usefull if your login is equal to your emailaddress)
        //        'domain'    - the value of the "domain" field is used
        //        '@mydomain.com' - the username is used and the given string will be appended
        'IMAP_DEFAULTFROM' => IMAP_DEFAULTFROM,
        // copy outgoing mail to this folder. If not set z-push will try the default folders
        'IMAP_SENTFOLDER' => IMAP_SENTFOLDER,
        // forward messages inline (default false - as attachment)
        'IMAP_INLINE_FORWARD' => IMAP_INLINE_FORWARD,
        // use imap_mail() to send emails (default) - if false mail() is used
        'IMAP_USE_IMAPMAIL' => IMAP_USE_IMAPMAIL,
	 //entension for external email provider
	 'IMAP_USERNAMEEXTENSION' => IMAP_USERNAMEEXTENSION,
    );

    // *************************
    //  BackendCalDAV settings
    // *************************
    public static $BackendCalDAV_config = array(
        'CALDAV_SERVER' => CALDAV_SERVER,
        'CALDAV_PORT' => CALDAV_PORT,
        'CALDAV_PATH' => CALDAV_PATH,
    );

    // *************************
    //  BackendCardDAV settings
    // *************************
    public static $BackendCardDAV_config = array(
        'CARDDAV_SERVER' => CARDDAV_SERVER,
        'CARDDAV_PORT' => CARDDAV_PORT,
        'CARDDAV_PATH' => CARDDAV_PATH,
        'CARDDAV_PRINCIPAL' => CARDDAV_PRINCIPAL,
    );

    // *************************
    //  BackendCardDAV_OC5 settings
    // *************************
    public static $BackendCardDAV_OC5_config = array(
        'CARDDAV_SERVER_OC5' => CARDDAV_SERVER_OC5,
        'CARDDAV_PORT_OC5' => CARDDAV_PORT_OC5,
        'CARDDAV_PATH_OC5' => CARDDAV_PATH_OC5,
	 'CARDDAV_PORT_OC5' => CARDDAV_PORT_OC5,
	 'CARDDAV_CONTACTS_FOLDER_NAME_OC5' => CARDDAV_CONTACTS_FOLDER_NAME_OC5,
	 'CARDDAV_FILEAS_ALLWAYSOVERRIDE_OC5' => CARDDAV_FILEAS_ALLWAYSOVERRIDE_OC5,
	 'CARDDAV_READONLY_OC5' => CARDDAV_READONLY_OC5,
	 'CARDDAV_SYNC_ON_PING_OC5' => CARDDAV_SYNC_ON_PING_OC5,
    );

    // *************************
    //  BackendLDAP settings
    // *************************
    public static $BackendLDAP_config = array(
        'LDAP_SERVER' => LDAP_SERVER,
        'LDAP_SERVER_PORT' => LDAP_SERVER_PORT,
        'LDAP_USER_DN' => LDAP_USER_DN,
        'LDAP_BASE_DNS' => LDAP_BASE_DNS,
    );

    // *************************
    //  BackendCombined settings
    // *************************
    /**
     * Returns the configuration of the combined backend
     *
     * @access public
     * @return array
    *
     */
    public static function GetBackendCombinedConfig() {
        //use a function for it because php does not allow
        //assigning variables to the class members (expecting T_STRING)
        return array(
            //the order in which the backends are loaded.
            //login only succeeds if all backend return true on login
            //sending mail: the mail is sent with first backend that is able to send the mail
            'backends' => array(
/*

                ),
                'l' => array(
                    'name' => 'BackendLDAP',
                    'config' => self::$BackendLDAP_config,
                ),
                'd' => array(
                    'name' => 'BackendCardDAV',
                    'config' => self::$BackendCardDAV_config,
                ),
*/ 
                'i' => array(
                    'name' => 'BackendIMAP',
                    'config' => self::$BackendIMAP_config,
                ),
                'c' => array(
                    'name' => 'BackendCalDAV',
                    'config' => self::$BackendCalDAV_config,
                ),
                'k' => array(
                    'name' => 'BackendCardDAV_OC5',
                    'config' => self::$BackendCardDAV_OC5_config,
		  ),
            ),
            'delimiter' => '/',
            //force one type of folder to one backend
            //it must match one of the above defined backends
            'folderbackend' => array(
                SYNC_FOLDER_TYPE_INBOX => 'i',
                SYNC_FOLDER_TYPE_DRAFTS => 'i',
                SYNC_FOLDER_TYPE_WASTEBASKET => 'i',
                SYNC_FOLDER_TYPE_SENTMAIL => 'i',
                SYNC_FOLDER_TYPE_OUTBOX => 'i',
                SYNC_FOLDER_TYPE_TASK => 'c',
                SYNC_FOLDER_TYPE_APPOINTMENT => 'c',
                SYNC_FOLDER_TYPE_CONTACT => 'k',
 		  //SYNC_FOLDER_TYPE_NOTE => 'c',
                //SYNC_FOLDER_TYPE_JOURNAL => 'c',
                SYNC_FOLDER_TYPE_OTHER => 'i',
                SYNC_FOLDER_TYPE_USER_MAIL => 'i',
                SYNC_FOLDER_TYPE_USER_APPOINTMENT => 'c',
                SYNC_FOLDER_TYPE_USER_CONTACT => 'k',
                SYNC_FOLDER_TYPE_USER_TASK => 'c',
                //SYNC_FOLDER_TYPE_USER_JOURNAL => 'c',
 		  //SYNC_FOLDER_TYPE_USER_NOTE => 'c',
                SYNC_FOLDER_TYPE_UNKNOWN => 'i',
            ),
            //creating a new folder in the root folder should create a folder in one backend
            'rootcreatefolderbackend' => 'i',
        );
    }
}
?>
