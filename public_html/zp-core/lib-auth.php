<?php
/**
 * Zenphoto USER credentials handlers
 *
 * An alternate authorization script may be provided to override this script. To do so, make a script that
 * implements the classes declared below. Place the new script inthe <ZENFOLDER>/plugins/alt/ folder. Zenphoto
 * will then will be automatically loaded the alternate script in place of this one.
 *
 * Replacement libraries must implement two classes:
 * 		"Authority" class: Provides the methods used for user authorization and management
 * 			store an instantiation of this class in $_zp_authority.
 *
 * 		Administrator: supports the basic Zenphoto needs for object manipulation of administrators.
 * (You can include this script and extend the classes if that suits your needs.)
 *
 * The global $_zp_current_admin_obj represents the current admin with.
 * The library must instantiate its authority class and store the object in the global $_zp_authority
 * (Note, this library does instantiate the object as described. This is so its classes can
 * be used as parent classes for lib-auth implementations. If auth_zp.php decides to use this
 * library it will instantiate the class and store it into $_zp_authority.
 *
 * The following elements need to be present in any alternate implementation in the
 * array returned by getAdministrators().
 *
 * 		In particular, there should be array elements for:
 * 				'id' (unique), 'user' (unique),	'pass',	'name', 'email', 'rights', 'valid',
 * 				'group', and 'custom_data'
 *
 * 		So long as all these indices are populated it should not matter when and where
 *		the data is stored.
 *
 *		Administrator class methods are required for these elements as well.
 *
 * 		The getRights() method must define at least the rights defined by the method in
 * 		this library.
 *
 * 		The checkAuthorization() method should promote the "most privileged" Admin to
 * 		ADMIN_RIGHTS to insure that there is some user capable of adding users or
 * 		modifying user rights.
 *
 * @package classes
 */
// force UTF-8 Ø
require_once(dirname(__FILE__).'/classes.php');

class Zenphoto_Authority {

	var $admin_users = NULL;
	var $admin_groups = NULL;
	var $admin_all = NULL;
	var $rightsset = NULL;
	var $master_user = NULL;
	var $preferred_version = 3;
	var $supports_version = 3;

	/**
	 * class instantiation function
	 *
	 * @return lib_auth_options
	 */
	function Zenphoto_Authority() {
		$lib_auth_extratext = "";
		$salt = 'abcdefghijklmnopqursuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789~!@#$%^&*()_+-={}[]|;,.<>?/';
		$list = range(0, strlen($salt));
		shuffle($list);
		for ($i=0; $i < 30; $i++) {
			$lib_auth_extratext = $lib_auth_extratext . substr($salt, $list[$i], 1);
		}
		setOptionDefault('strong_hash', 0);
		setOptionDefault('extra_auth_hash_text', $lib_auth_extratext);
		setOptionDefault('min_password_lenght', 6);
		setOptionDefault('password_pattern', 'A-Za-z0-9   |   ~!@#$%&*_+`-(),.\^\'"/[]{}=:;?\|');
		$sql = 'SELECT * FROM '.prefix('administrators').' WHERE `valid`=1 ORDER BY `rights` DESC, `id` LIMIT 1';
		$master = query_single_row($sql,false);
		if ($master) {
			$this->master_user = $master['user'];
		}
	}

	/**
	 * Declares options used by lib-auth
	 *
	 * @return array
	 */
	function getOptionsSupported() {
		return array(	gettext('Minimum password length:') => array('key' => 'min_password_lenght', 'type' => OPTION_TYPE_TEXTBOX,
										'desc' => gettext('Minimum number of characters a password must contain.')),
									gettext('Password characters:') => array('key' => 'password_pattern', 'type' => OPTION_TYPE_CLEARTEXT,
										'desc' => gettext('Passwords must contain at least one of the characters from each of the groups. Groups are separated by "|". (Use "\|" to represent the "|" character in the groups.)')),
									gettext('settings')=> array('key'=>'lib_auth_info', 'type'=>OPTION_TYPE_CUSTOM,
										'order'=>9,
										'desc'=>'')
									);
	}

	/**
	 * Dummy for object inheritance purposes
	 */
	function handleOption($option, $currentValue) {
		global $_zp_current_admin_obj;
		if ($option=='lib_auth_info') {
			$class = get_class($this);
			if ($class != 'Zenphoto_Authority') {
				echo '<p class="notebox">'.sprintf(gettext('Authorization class <em>%s</em> is active.'),$class).'</p>';
			}
			$class = get_class($_zp_current_admin_obj);
			if ($class != 'Zenphoto_Administrator') {
				echo '<p class="notebox">'.sprintf(gettext('Administrator class <em>%s</em> is active.'),$class).'</p>';
			}
			echo '<p>'.sprintf(gettext('Password hash seed: <span><small style="color:gray">%s</small></span>'),html_encode(getOption('extra_auth_hash_text'))).'</p>';
			if (getOption('strong_hash')) {
				$hash = 'sha1';
			} else {
				$hash = 'md5';
			}
			echo '<p>'.sprintf(gettext('<em>%s</em> hashing is activated'),$hash).'</p>';
		}
	}

	function getVersion() {
		$v = getOption('libauth_version');
		if (empty($v)) {
			return $this->preferred_version;
		} else {
			return $v;
		}
	}

	/**
	 * Returns the hash of the zenphoto password
	 *
	 * @param string $user
	 * @param string $pass
	 * @return string
	 */
	function passwordHash($user, $pass) {
		if (getOption('strong_hash')) {
			$hash = sha1($user . $pass . HASH_SEED);
		} else {
			$hash = md5($user . $pass . HASH_SEED);
		}
		if (DEBUG_LOGIN) { debugLog("passwordHash($user, $pass)[{HASH_SEED}]:$hash"); }
		return $hash;
	}

	/**
	 * Checks to see if password follows rules
	 * Returns error message if not.
	 *
	 * @param string $pass
	 * @return string
	 */
	function validatePassword($pass) {
		$l = getOption('min_password_lenght');
		if ($l > 0) {
			if (strlen($pass) < $l) return sprintf(gettext('Password must be at least %u characters'), $l);
		}
		$p = getOption('password_pattern');
		if (!empty($p)) {
			$strong = true;
			$p = str_replace('\|', "\t", $p);
			$patterns = explode('|', $p);
			$p2 = '';
			foreach ($patterns as $pat) {
				$pat = trim(str_replace("\t", '|', $pat));
				if (!empty($pat)) {
					$p2 .= '{<em>'.$pat.'</em>}, ';

					$patrn = '';
					foreach (array('0-9','a-z','A-Z') as $try) {
						if (preg_match('/['.$try.']-['.$try.']/', $pat, $r)) {
							$patrn .= $r[0];
							$pat = str_replace($r[0],'',$pat);
						}
					}
					$patrn .= addcslashes($pat,'\\/.()[]^-');
					$strong = $strong && preg_match('/(['.$patrn.'])/', $pass);
				}
			}
			if (!$strong)	{
				return sprintf(gettext('Password must contain at least one of %s'), substr($p2,0,-2));
			}
		}
		return false;
	}

	/**
	 * Returns text describing password constraints
	 *
	 * @return string
	 */
	function passwordNote() {
		$l = getOption('min_password_lenght');
		$p = getOption('password_pattern');
		$p = str_replace('\|', "\t", $p);
		$c = 0;
		if (!empty($p)) {
			$patterns = explode('|', $p);
			$text = '';
			foreach ($patterns as $pat) {
				$pat = trim(str_replace("\t", '|', $pat));
				if (!empty($pat)) {
					$c++;
					$text .= ', <span style="white-space: nowrap;"><strong>{</strong><em>'.html_encode($pat).'</em><strong>}</strong></span>';
				}
			}
			$text = substr($text, 2);
		}
		if ($c > 0) {
			if ($l > 0) {
				$msg = '<p class="notebox">'.sprintf(ngettext('<strong>Note:</strong> passwords must be at least %1$u characters long and contain at least one character from %2$s.',
															'<strong>Note</strong>: passwords must be at least %1$u characters long and contain at least one character from each of the following groups: %2$s.', $c), $l, $text).'</p>';;
			} else {
				$msg = '<p class="notebox">'.sprintf(ngettext('<strong>Note</strong>: passwords must contain at least one character from %s.',
															'<strong>Note</strong>: passwords must contain at least one character from each of the following groups: %s.', $c), $text).'</p>';
			}
		} else {
			if ($l > 0) {
				$msg = sprintf(gettext('<strong>Note</strong>: passwords must be at least %u characters long.'), $l);
			} else {
				$msg = '';
			}
		}
		return $msg;
	}

	/**
	 * Returns an array of admin users, indexed by the userid and ordered by "privileges"
	 *
	 * The array contains the id, hashed password, user's name, email, and admin privileges
	 *
	 * @param string $what: 'all' for everything, 'users' for just users 'groups' for groups and templates
	 * @return array
	 */
	function getAdministrators($what='users') {
		if (is_null($this->admin_users)) {
			$this->admin_all = $this->admin_groups = $this->admin_users = array();
			$sql = 'SELECT * FROM '.prefix('administrators').' ORDER BY `rights` DESC, `id`';
			$admins = query_full_array($sql, false);
			if ($admins !== false) {
				foreach($admins as $user) {
					$this->admin_all[$user['id']] = $user;
					switch ($user['valid']) {
						case 1:
							$this->admin_users[$user['id']] = $user;
							break;
						case 0:
							$this->admin_groups[$user['id']] = $user;
							break;
						default:
							//these are found in "all"
							break;
					}
				}
			}
		}
		switch ($what) {
			case 'users':
				return $this->admin_users;
			case 'groups':
				return $this->admin_groups;
			default:
				return $this->admin_all;
		}
	}

	/**
	 * Returns an admin object from the $pat:$criteria
	 * @param string $criteria [ match => criteria ]
	 * @return Zenphoto_Administrator
	 */
	function getAnAdmin($criteria) {
		$selector = array();
		foreach ($criteria as $match=>$value) {
			if (is_numeric($value)) {
				$selector[] = $match.$value;
			} else {
				$selector[] = $match.db_quote($value);
			}
		}
		$sql = 'SELECT * FROM '.prefix('administrators').' WHERE '.implode(' AND ',$selector).' LIMIT 1';
		$admin = query_single_row($sql,false);
		if ($admin) {
			return $this->newAdministrator($admin['user'], $admin['valid']);
		} else {
			return NULL;
		}
	}

	/**
	 * Retuns the administration rights of a saved authorization code
	 * Will promote an admin to ADMIN_RIGHTS if he is the most privileged admin
	 *
	 * @param string $authCode the hash code to check
	 * @param int $id whom we think this is
	 *
	 * @return bit
	 */
	function checkAuthorization($authCode, $id) {
		global $_zp_current_admin_obj, $_zp_reset_admin, $_zp_null_account;
		$_zp_current_admin_obj = NULL;
		if (DEBUG_LOGIN) { debugLogBacktrace("checkAuthorization($authCode, $id)");	}

		$admins = $this->getAdministrators();
		if (DEBUG_LOGIN) { debugLogArray("checkAuthorization: admins",$admins);	}
		if ((count($admins) == 0)) {
			if (DEBUG_LOGIN) { debugLog("checkAuthorization: no admins"); }
			$_zp_null_account = true;
			return ADMIN_RIGHTS; //no admins or reset request
		}
		if ($_zp_reset_admin) {
			if (DEBUG_LOGIN) { debugLog("checkAuthorization: reset request"); }
			if (is_object($_zp_reset_admin)) {
				return $_zp_reset_admin->getRights();
			}
		}

		if (empty($authCode)) return 0; //  so we don't "match" with an empty password
		$rights = 0;
		$criteria = array('`pass`=' => $authCode, '`valid`=' => 1);
		if (!is_null($id)) {
			$criteria['`id`='] = $id;
		}
		$user = $this->getAnAdmin($criteria);
		if (is_object($user)) {
			$_zp_current_admin_obj = $user;
			$rights = $user->getRights();
			if (DEBUG_LOGIN) { debugLog(sprintf('checkAuthorization: from $authcode %X',$rights));	}
			return $rights;
		}
		$_zp_current_admin_obj = NULL;
		if (DEBUG_LOGIN) { debugLog("checkAuthorization: no match");	}
		return 0; // no rights
	}

	/**
	 * Checks a logon user/password against admins
	 *
	 * Returns true if there is a match
	 *
	 * @param string $user
	 * @param string $pass
	 * @param bool $admin_login will be true if the login for the backend. If false, it is a guest login beging checked for admin credentials
	 * @return bool
	 */
	function checkLogon($user, $pass, $admin_login) {
		global $_zp_authority;
		$success = false;
		$hash = $this->passwordHash($user, $pass);
		$userobj = $_zp_authority->getAnAdmin(array('`user`=' => $user, '`pass`=' => $hash, '`valid`=' => 1));
		return $userobj;
	}

	/**
	 * Returns the email addresses of the Admin with ADMIN_USERS rights
	 *
	 * @param bit $rights what kind of admins to retrieve
	 * @return array
	 */
	function getAdminEmail($rights=NULL) {
		if (is_null($rights)) {
			$rights = ADMIN_RIGHTS;
		}
		$emails = array();
		$admins = $this->getAdministrators();
		foreach ($admins as $user) {
			if (($user['rights'] & $rights)  && is_valid_email_zp($user['email'])) {
				$name = $user['name'];
				if (empty($name)) {
					$name = $user['user'];
				}
				$emails[$name] = $user['email'];
			}
		}
		return $emails;
	}

	/**
	 * Migrates credentials
	 *
	 * @param int $oldversion
	 */
	function migrateAuth($to) {
		if ($to > $this->supports_version || $to < $this->preferred_version-1) {
			trigger_error(sprintf(gettext('Cannot migrate rights to version %1$s (Zenphoto_Authority supports only %2$s and %3$s.)'),$to,$_zp_authority->supports_version,$this->preferred_version), E_USER_NOTICE);
			return false;
		}
		$success = true;
		$oldversion = $this->getVersion();
		setOption('libauth_version',$to);
		$this->admin_users = array();
		$sql = "SELECT * FROM ".prefix('administrators')."ORDER BY `rights` DESC, `id`";
		$admins = query_full_array($sql, false);
		if (count($admins)>0) { // something to migrate
			$oldrights = array();
			foreach ($this->getRights($oldversion) as $key=>$right) {
				$oldrights[$key] = $right['value'];
			}
			$currentrights = $this->getRights($to);
			foreach($admins as $user) {
				$update = false;
				$rights = $user['rights'];
				$newrights = 0;
				foreach ($currentrights as $key=>$right) {
					if ($right['display']) {
						if (array_key_exists($key, $oldrights) && $rights & $oldrights[$key]) {
							$newrights = $newrights | $right['value'];
						}
					}
				}
				if ($to == 3 && $oldversion < 3) {
					if ($rights & $oldrights['VIEW_ALL_RIGHTS']) {
						$updaterights = $currentrights['VIEW_ALBUMS_RIGHTS']['value'] | $currentrights['VIEW_PAGES_RIGHTS']['value'] |
													$currentrights['VIEW_NEWS_RIGHTS']['value'] | $currentrights['VIEW_SEARCH_RIGHTS']['value']	|
													$currentrights['VIEW_GALLERY_RIGHTS']['value'] | $currentrights['VIEW_FULLIMAGE_RIGHTS']['value'];
						$newrights = $newrights | $updaterights;
					}
				}
				if ($oldversion == 3 && $to < 3) {
					if ($oldrights['VIEW_ALBUMS_RIGHTS'] || $oldrights['VIEW_PAGES_RIGHTS'] || $oldrights['VIEW_NEWS_RIGHTS']) {
						$newrights = $newrights | $currentrights['VIEW_ALL_RIGHTS']['value'];
					}
				}
				if ($oldversion == 1) {	// need to migrate zenpage rights
					if ($rights & $oldrights['ZENPAGE_RIGHTS']) {
						$newrights = $newrights | ZENPAGE_PAGES_RIGHTS | ZENPAGE_NEWS_RIGHTS | FILES_RIGHTS;
					}
				}
				if ($to == 1) {
					if ($rights & ($oldrights['ZENPAGE_PAGES_RIGHTS'] | $oldrights['ZENPAGE_NEWS_RIGHTS'] | $oldrights['FILES_RIGHTS'])) {
						$newrights = $newrights | ZENPAGE_RIGHTS;
					}
				}

				$sql = 'UPDATE '.prefix('administrators').' SET `rights`='.$newrights.' WHERE `id`='.$user['id'];
				$success = $success && query($sql);
			} // end loop
		}
		return $success;
	}

	/**
	 * Updates a field in admin record(s)
	 *
	 * @param string $update name of the field
	 * @param mixed $value what to store
	 * @param array $constraints on the update [ field<op>,value ]
	 * @return mixed Query result
	 */
	function updateAdminField($update, $value, $constraints) {
		$where = '';
		foreach ($constraints as $field=>$clause) {
			if (!empty($where)) $where .= ' AND ';
			if (is_numeric($clause)) {
				$where .= $field.$clause;
			} else {
				$where .= $field.db_quote($clause);
			}
		}
		if (is_null($value)) {
			$value = 'NULL';
		} else {
			$value = db_quote($value);
		}
		$sql = 'UPDATE '.prefix('administrators').' SET `'.$update.'`='.$value.' WHERE '.$where;
		$result = query($sql);
		return $result;
	}

	/**
	 * Instantiates and returns administrator object
	 * @param $name
	 * @param $valid
	 * @return object
	 */
	function newAdministrator($name, $valid=1) {
		$user = new Zenphoto_Administrator($name, $valid);
		return $user;
	}

	/**
	 * Returns an array of the rights definitions for $version (default returns current version rights)
	 *
	 * @param $version
	 */
	function getRights($version=NULL) {
		if (empty($version)) {
			$v = $this->getVersion();
		} else {
			$v = $version;
		}
		switch ($v) {
			case 1:
				$rightsset = array(	'NO_RIGHTS' => array('value'=>2,'name'=>gettext('No rights'),'set'=>'','display'=>false,'hint'=>''),
														'OVERVIEW_RIGHTS' => array('value'=>4,'name'=>gettext('Overview'),'set'=>'','display'=>true,'hint'=>''),
														'VIEW_ALL_RIGHTS' => array('value'=>8,'name'=>gettext('View all'),'set'=>'','display'=>true,'hint'=>''),
														'UPLOAD_RIGHTS' => array('value'=>16,'name'=>gettext('Upload'),'set'=>'','display'=>true,'hint'=>''),
														'POST_COMMENT_RIGHTS'=> array('value'=>32,'name'=>gettext('Post comments'),'set'=>'','display'=>true,'hint'=>''),
														'COMMENT_RIGHTS' => array('value'=>64,'name'=>gettext('Comments'),'set'=>'','display'=>true,'hint'=>''),
														'ALBUM_RIGHTS' => array('value'=>256,'name'=>gettext('Album'),'set'=>'','display'=>true,'hint'=>''),
														'MANAGE_ALL_ALBUM_RIGHTS' => array('value'=>512,'name'=>gettext('Manage all albums'),'set'=>'','display'=>true,'hint'=>''),
														'THEMES_RIGHTS' => array('value'=>1024,'name'=>gettext('Themes'),'set'=>'','display'=>true,'hint'=>''),
														'ZENPAGE_RIGHTS' => array('value'=>2049,'name'=>gettext('Zenpage'),'set'=>'','display'=>true,'hint'=>''),
														'TAGS_RIGHTS' => array('value'=>4096,'name'=>gettext('Tags'),'set'=>'','display'=>true,'hint'=>''),
														'OPTIONS_RIGHTS' => array('value'=>8192,'name'=>gettext('Options'),'set'=>'','display'=>true,'hint'=>''),
														'ADMIN_RIGHTS' => array('value'=>65536,'name'=>gettext('Admin'),'set'=>'','display'=>true,'hint'=>''));
				break;
			case 2:
				$rightsset = array(	'NO_RIGHTS' => array('value'=>1,'name'=>gettext('No rights'),'set'=>'','display'=>false,'hint'=>''),
														'OVERVIEW_RIGHTS' => array('value'=>pow(2,2),'name'=>gettext('Overview'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may view the admin overview page.')),
														'VIEW_ALL_RIGHTS' => array('value'=>pow(2,4),'name'=>gettext('View all'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may view all of the gallery regardless of protection of the page. Without this right, the user can view only public ones and those checked in his managed object lists or as granted by View Search or View Gallery.')),
														'UPLOAD_RIGHTS' => array('value'=>pow(2,6),'name'=>gettext('Upload'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may upload to the albums for which they have management rights.')),
														'POST_COMMENT_RIGHTS'=> array('value'=>pow(2,8),'name'=>gettext('Post comments'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('When the comment_form plugin is used for comments and its "Only members can comment" option is set, only users with this right may post comments.')),
														'COMMENT_RIGHTS' => array('value'=>pow(2,10),'name'=>gettext('Comments'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may make comments tab changes.')),
														'ALBUM_RIGHTS' => array('value'=>pow(2,12),'name'=>gettext('Albums'),'set'=>gettext('Albums'),'display'=>true,'hint'=>gettext('Users with this right may access the "albums" tab to make changes.')),
														'ZENPAGE_PAGES_RIGHTS' => array('value'=>pow(2,14),'name'=>gettext('Pages'),'set'=>gettext('Pages'),'display'=>true,'hint'=>gettext('Users with this right may edit and manage Zenpage pages.')),
														'ZENPAGE_NEWS_RIGHTS' => array('value'=>pow(2,16),'name'=>gettext('News'),'set'=>gettext('News'),'display'=>true,'hint'=>gettext('Users with this right may edit and manage Zenpage articles and categories.')),
														'FILES_RIGHTS' => array('value'=>pow(2,18),'name'=>gettext('Files'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Allows the user access to the "filemanager" located on the upload: files sub-tab.')),
														'MANAGE_ALL_PAGES_RIGHTS' => array('value'=>pow(2,20),'name'=>gettext('Manage all pages'),'set'=>gettext('Pages'),'display'=>true,'hint'=>gettext('Users who do not have "Admin" rights normally are restricted to manage only objects to which they have been assigned. This right allows them to manage any Zenpage page.')),
														'MANAGE_ALL_NEWS_RIGHTS' => array('value'=>pow(2,22),'name'=>gettext('Manage all news'),'set'=>gettext('News'),'display'=>true,'hint'=>gettext('Users who do not have "Admin" rights normally are restricted to manage only objects to which they have been assigned. This right allows them to manage any Zenpage news article or category.')),
														'MANAGE_ALL_ALBUM_RIGHTS' => array('value'=>pow(2,24),'name'=>gettext('Manage all albums'),'set'=>gettext('Albums'),'display'=>true,'hint'=>gettext('Users who do not have "Admin" rights normally are restricted to manage only objects to which they have been assigned. This right allows them to manage any album in the gallery.')),
														'THEMES_RIGHTS' => array('value'=>pow(2,26),'name'=>gettext('Themes'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may make themes related changes. These are limited to the themes associated with albums checked in their managed albums list.')),
														'TAGS_RIGHTS' => array('value'=>pow(2,28),'name'=>gettext('Tags'),'set'=>gettext('General'),'display'=>true,'hint'=>gettext('Users with this right may make additions and changes to the set of tags.')),
														'OPTIONS_RIGHTS' => array('value'=>pow(2,29),'name'=>gettext('Options'),'set'=>gettext('General'),'display'=>true,'hint'=>gettext('Users with this right may make changes on the options tabs.')),
														'ADMIN_RIGHTS' => array('value'=>pow(2,30),'name'=>gettext('Admin'),'set'=>gettext('General'),'display'=>true,'hint'=>gettext('The master privilege. A user with "Admin" can do anything. (No matter what his other rights might indicate!)')));
				break;
			case 3:
				$rightsset = array(	'NO_RIGHTS' => array('value'=>1,'name'=>gettext('No rights'),'set'=>'','display'=>false,'hint'=>''),

														'OVERVIEW_RIGHTS' => array('value'=>pow(2,2),'name'=>gettext('Overview'),'set'=>gettext('General'),'display'=>true,'hint'=>gettext('Users with this right may view the admin overview page.')),

														'VIEW_GALLERY_RIGHTS' => array('value'=>pow(2,4),'name'=>gettext('View gallery'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may view otherwise protected generic gallery pages.')),
														'VIEW_SEARCH_RIGHTS' => array('value'=>pow(2,5),'name'=>gettext('View search'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may view search pages even if password protected.')),
														'VIEW_FULLIMAGE_RIGHTS' => array('value'=>pow(2,6),'name'=>gettext('View fullimage'),'set'=>gettext('Albums'),'display'=>true,'hint'=>gettext('Users with this right may view all full sized (raw) images.')),
														'VIEW_NEWS_RIGHTS' => array('value'=>pow(2,7),'name'=>gettext('View news'),'set'=>gettext('News'),'display'=>true,'hint'=>gettext('Users with this right may view all zenpage news articles.')),
														'VIEW_PAGES_RIGHTS' => array('value'=>pow(2,8),'name'=>gettext('View pages'),'set'=>gettext('Pages'),'display'=>true,'hint'=>gettext('Users with this right may view all zenpage pages.')),
														'VIEW_ALBUMS_RIGHTS' => array('value'=>pow(2,9),'name'=>gettext('View albums'),'set'=>gettext('Albums'),'display'=>true,'hint'=>gettext('Users with this right may view all albums (and their images).')),

														'POST_COMMENT_RIGHTS'=> array('value'=>pow(2,11),'name'=>gettext('Post comments'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('When the comment_form plugin is used for comments and its "Only members can comment" option is set, only users with this right may post comments.')),
														'COMMENT_RIGHTS' => array('value'=>pow(2,12),'name'=>gettext('Comments'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may make comments tab changes.')),
														'UPLOAD_RIGHTS' => array('value'=>pow(2,13),'name'=>gettext('Upload'),'set'=>gettext('Albums'),'display'=>true,'hint'=>gettext('Users with this right may upload to the albums for which they have management rights.')),

														'ZENPAGE_NEWS_RIGHTS' => array('value'=>pow(2,15),'name'=>gettext('News'),'set'=>gettext('News'),'display'=>false,'hint'=>gettext('Users with this right may edit and manage Zenpage articles and categories.')),
														'ZENPAGE_PAGES_RIGHTS' => array('value'=>pow(2,16),'name'=>gettext('Pages'),'set'=>gettext('Pages'),'display'=>false,'hint'=>gettext('Users with this right may edit and manage Zenpage pages.')),
														'FILES_RIGHTS' => array('value'=>pow(2,17),'name'=>gettext('Files'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Allows the user access to the "filemanager" located on the upload: files sub-tab.')),
														'ALBUM_RIGHTS' => array('value'=>pow(2,18),'name'=>gettext('Albums'),'set'=>gettext('Albums'),'display'=>false,'hint'=>gettext('Users with this right may access the "albums" tab to make changes.')),

														'MANAGE_ALL_NEWS_RIGHTS' => array('value'=>pow(2,21),'name'=>gettext('Manage all news'),'set'=>gettext('News'),'display'=>true,'hint'=>gettext('Users who do not have "Admin" rights normally are restricted to manage only objects to which they have been assigned. This right allows them to manage any Zenpage news article or category.')),
														'MANAGE_ALL_PAGES_RIGHTS' => array('value'=>pow(2,22),'name'=>gettext('Manage all pages'),'set'=>gettext('Pages'),'display'=>true,'hint'=>gettext('Users who do not have "Admin" rights normally are restricted to manage only objects to which they have been assigned. This right allows them to manage any Zenpage page.')),
														'MANAGE_ALL_ALBUM_RIGHTS' => array('value'=>pow(2,23),'name'=>gettext('Manage all albums'),'set'=>gettext('Albums'),'display'=>true,'hint'=>gettext('Users who do not have "Admin" rights normally are restricted to manage only objects to which they have been assigned. This right allows them to manage any album in the gallery.')),

														'THEMES_RIGHTS' => array('value'=>pow(2,26),'name'=>gettext('Themes'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may make themes related changes. These are limited to the themes associated with albums checked in their managed albums list.')),

														'TAGS_RIGHTS' => array('value'=>pow(2,28),'name'=>gettext('Tags'),'set'=>gettext('Gallery'),'display'=>true,'hint'=>gettext('Users with this right may make additions and changes to the set of tags.')),
														'OPTIONS_RIGHTS' => array('value'=>pow(2,29),'name'=>gettext('Options'),'set'=>gettext('General'),'display'=>true,'hint'=>gettext('Users with this right may make changes on the options tabs.')),
														'ADMIN_RIGHTS' => array('value'=>pow(2,30),'name'=>gettext('Admin'),'set'=>gettext('General'),'display'=>true,'hint'=>gettext('The master privilege. A user with "Admin" can do anything. (No matter what his other rights might indicate!)')));
				break;
		}
		$allrights = 0;
		foreach ($rightsset as $key=>$right) {
			$allrights = $allrights | $right['value'];
		}
		$rightsset['ALL_RIGHTS'] =	array('value'=>$allrights,'name'=>gettext('All rights'),'display'=>false);
		$rightsset['DEFAULT_RIGHTS'] =	array('value'=>$rightsset['OVERVIEW_RIGHTS']['value']+$rightsset['POST_COMMENT_RIGHTS']['value'],'name'=>gettext('Default rights'),'display'=>false);
		if (isset($rightsset['VIEW_ALL_RIGHTS']['value'])) {
			$rightsset['DEFAULT_RIGHTS']['value'] = $rightsset['DEFAULT_RIGHTS']['value']|$rightsset['VIEW_ALL_RIGHTS']['value'];
		} else {
			$rightsset['DEFAULT_RIGHTS']['value'] = $rightsset['DEFAULT_RIGHTS']|$rightsset['VIEW_ALBUMS_RIGHTS']['value']|
																		 $rightsset['VIEW_PAGES_RIGHTS']['value']|$rightsset['VIEW_NEWS_RIGHTS']['value']|
																		 $rightsset['VIEW_SEARCH_RIGHTS']['value']|$rightsset['VIEW_GALLERY_RIGHTS']['value'];
		}
		$rightsset = sortMultiArray($rightsset,'value',true,false,false);
		return $rightsset;
	}

	function getResetTicket($user, $pass) {
		$req = time();
		$ref = sha1($req . $user . $pass);
		$time = bin2hex(rc4('ticket'.HASH_SEED, $req));
		return $time.$ref;
	}

	function validateTicket($ticket, $user) {
		global $_zp_null_account, $_zp_reset_admin;
		$admins = $this->getAdministrators();
		foreach ($admins as $tuser) {
			if ($tuser['user'] == $user) {
				$request_date = rc4('ticket'.HASH_SEED, pack("H*", $time = substr($ticket,0,20)));
				$ticket = substr($ticket, 20);
				$ref = sha1($request_date . $user . $tuser['pass']);
				if ($ref === $ticket) {
					if (time() <= ($request_date + (3 * 24 * 60 * 60))) { // limited time offer
						$_zp_reset_admin = new Zenphoto_Administrator($user, 1);
						$_zp_null_account = true;
					}
				}
				break;
			}
		}
	}

	/**
	 * Set log-in cookie for a user
	 * @param string $user
	 */
	function logUser($user) {
		$user->lastlogon = $user->get('loggedin');
		$user->set('loggedin',date('Y-m-d H:i:s'));
		$user->save();
		zp_setCookie("zenphoto_auth", $user->getPass(), NULL, NULL, secureServer());
	}

	/**
	 * User authentication support
	 */
	function handleLogon() {
		global $_zp_authority, $_zp_current_admin_obj, $_zp_login_error, $_zp_captcha, $_zp_loggedin;
		if (isset($_POST['login']) && isset($_POST['user']) && isset($_POST['pass'])) {
			$post_user = sanitize($_POST['user']);
			$post_pass = sanitize($_POST['pass'],0);
			$user = $this->checkLogon($post_user, $post_pass, true);
			if ($user) {
				$_zp_loggedin = $user->getRights();
			} else {
				$_zp_loggedin = false;
			}
			$_zp_loggedin = zp_apply_filter('admin_login_attempt', $_zp_loggedin, $post_user, $post_pass);
			if ($_zp_loggedin) {
				$this->logUser($user);
			} else {
				// Clear the cookie, just in case
				zp_setCookie("zenphoto_auth", "", -368000);
				// was it a request for a reset?
				if (isset($_POST['code_h']) && $_zp_captcha->checkCaptcha(trim($post_pass), sanitize($_POST['code_h'],3))) {
					require_once(dirname(__FILE__).'/class-load.php'); // be sure that the plugins are loaded for the mail handler
					if (empty($post_user)) {
						$requestor = gettext('You are receiving this e-mail because of a password reset request on your Zenphoto gallery.');
					} else {
						$requestor = sprintf(gettext("You are receiving this e-mail because of a password reset request on your Zenphoto gallery from a user who tried to log in as %s."),$post_user);
					}
					$admins = $_zp_authority->getAdministrators();
					$mails = array();
					$user = NULL;
					foreach ($admins as $key=>$tuser) {
						if (!empty($tuser['email'])) {
							if (!empty($post_user) && ($tuser['user'] == $post_user || $tuser['email'] == $post_user)) {
								$name = $tuser['name'];
								if (empty($name)) {
									$name = $tuser['user'];
								}
								$mails[$name] = $tuser['email'];
								$user = $tuser;
								unset($admins[$key]);	// drop him from alternate list.
							} else {
								if (!($tuser['rights'] & ADMIN_RIGHTS)) {
									unset($admins[$key]);	// eliminate any peons from the list
								}
							}
						} else {
							unset($admins[$key]);	// we want to ignore groups and users with no email address here!
						}
					}

					$cclist = array();
					foreach ($admins as $tuser) {
						$name = $tuser['name'];
						if (empty($name)) {
							$name = $tuser['user'];
						}
						if (is_null($user)) {
							$user = $tuser;
							$mails[$name] = $tuser['email'];
						} else {
							$cclist[$name] = $tuser['email'];
						}
					}
					if (is_null($user)) {
						$_zp_login_error = gettext('There was no one to which to send the reset request.');
					} else {
							$ref = $this->getResetTicket($user['user'], $user['pass']);
							$msg = "\n".$requestor.
									"\n".sprintf(gettext("To reset your Zenphoto Admin passwords visit: %s"),FULLWEBPATH."/".ZENFOLDER."/admin-users.php?ticket=$ref&user=".$user['user']) .
									"\n".gettext("If you do not wish to reset your passwords just ignore this message. This ticket will automatically expire in 3 days.");
							$err_msg = zp_mail(gettext("The Zenphoto information you requested"), $msg, $mails, $cclist);
							if (empty($err_msg)) {
								$_zp_login_error = 2;
							} else {
								$_zp_login_error = $err_msg;
							}
						}
					} else {
					$_zp_login_error = 1;
				}
			}
		}
		return $_zp_loggedin;
	}

	/**
	 * Cleans up on logout
	 *
	 * NOTE: this presumes the general form of an authrization cookie is:
	 * zp_xxxxx_auth where xxxxx is the authority (e.g. gallery, image, search, ...)
	 */
	function handleLogout() {
		global $_zp_loggedin, $_zp_pre_authorization;
		$candidate = array();
		if (isset($_COOKIE)) {
			$candidate = $_COOKIE;
		}
		if (isset($_SESSION)) {
			$candidate = Array_merge($candidate, $_SESSION);
		}
		$candidate = array_unique($candidate);
		foreach ($candidate as $cookie=>$value) {
			switch ($cookie) {
				default:
					if (!preg_match('/zp_(.*)_auth/', $cookie, $result)) {
						break;	// not an auth cookie
					}
				case 'zenphoto_auth':
					zp_setCookie($cookie, "*", -368000);
					break;
			}
		}
		$_zp_loggedin = false;
		$_zp_pre_authorization = array();
		return true;
	}

	/**
	 * Checks saved cookies to see if a user is logged in
	 */
	function checkCookieCredentials() {
		if (getOption('strong_hash')) {
			$hashlen = 40;
		} else {
			$hashlen = 32;
		}
		$auth = zp_getCookie('zenphoto_auth');
		if (strlen($auth) > $hashlen) {
			$id = substr($auth, $hashlen);
			$auth = substr($auth, 0, $hashlen);
		} else {
			$id = NULL;
		}
		$_zp_loggedin = $this->checkAuthorization($auth, $id);
		if ($_zp_loggedin) {
			return $_zp_loggedin;
		} else {
			zp_setCookie("zenphoto_auth", "", -368000);
			return false;
		}
	}

	/**
	 * Print the login form for ZP. This will take into account whether mod_rewrite is enabled or not.
	 *
	 * @param string $redirect URL to return to after login
	 * @param bool $logo set to true to display the ADMIN zenphoto logo.
	 * @param bool $showUser set to true to display the user input
	 * @param bool $showCaptcha set to false to not display the forgot password captcha.
	 * @param string $hint optional hint for the password
	 *
	 */
	function printLoginForm($redirect=null, $logo=true, $showUser=true, $showCaptcha='*', $hint='') {
		global $_zp_login_error, $_zp_captcha, $_zp_authority;
		if (is_null($redirect)) {
			$redirect = WEBPATH.'/'.ZENFOLDER.'/admin.php';
		}
		if (isset($_POST['user'])) {
			$requestor = sanitize($_POST['user'], 3);
		} else {
			$requestor = '';
		}
		if (empty($requestor)) {
			if (isset($_GET['ref'])) {
				$requestor = sanitize($_GET['ref'], 0);
			}
		}
		$alt_handlers = zp_apply_filter('alt_login_handler',array());
		$star = '';
		$mails = array();
		if (!empty($requestor)) {
			$admin = $_zp_authority->getAnAdmin(array('`user`=' => $requestor, '`valid`=' => 1));
			if (is_object($admin) && $admin->getEmail()) {
				$star = $showCaptcha;
			}
		}
		if (!$star) {
			$admins = $_zp_authority->getAdministrators();
			while (count($admins)>0) {
				$user = array_shift($admins);
				if ($user['email']) {
					$star = $showCaptcha;
				}
			}
		}
		?>
		<div id="loginform">
		<?php
		if ($logo) echo "<p><img src=\"".WEBPATH.'/'.ZENFOLDER."/images/zen-logo.png\" title=\"ZenPhoto\" alt=\"ZenPhoto\" /></p>";
		switch ($_zp_login_error) {
			case 1:
				?>
				<div class="errorbox" id="message"><h2><?php echo gettext("There was an error logging in."); ?></h2><?php echo gettext("Check your username and password and try again.");?></div>
				<?php
				break;
			case 2:
				?>
				<div class="messagebox fade-message">
				<h2><?php echo gettext("A reset request has been sent."); ?></h2>
				</div>
				<?php
				break;
			default:
				if (!empty($_zp_login_error)) {
					?>
					<div class="errorbox fade-message">
					<h2><?php echo $_zp_login_error; ?></h2>
					</div>
					<?php
				}
				break;
		}
		?>
		<form name="login" action="#" method="post">
		<input type="hidden" name="login" value="1" />
		<input type="hidden" name="password" value="1" />
		<input type="hidden" name="redirect" value="<?php echo html_encode($redirect); ?>" />
		<fieldset id="logon_box">
			<?php
			if (empty($alt_handlers)) {
				$ledgend = gettext('Login');
			} else {
				$gallery = new Gallery();
				?>
				<script type="text/javascript">
					<!--
					var handlers = [];
					<?php
					$list = '<select id="logon_choices" onchange="changeHandler(handlers[$(this).val()]);">'.
										'<option value="0">'.html_encode(get_language_string($gallery->getTitle())).'</option>';
					$c = 0;
					foreach ($alt_handlers as $handler=>$details) {
						$c++;
						$details['params'][] = 'redirect='.$redirect;
						if (!empty($requestor)) {
							$details['params'][] = 'requestor='.$requestor;
						}
						echo "handlers[".$c."]=['".$details['script']."','".implode("','", $details['params'])."'];";

						$list .= '<option value="'.$c.'">'.$handler.'</option>';
					}
					$list .= '</select>';
					$ledgend = sprintf(gettext('Logon using:%s'),$list);
					?>
					function changeHandler(handler) {
						handler.push('user='+$('#user').val());
						var script = handler.shift();
						launchScript(script,handler);
					}
					-->
				</script>
				<?php
			}
			?>
			<legend><?php echo $ledgend; ?></legend>
			<table class="password">
				<?php
				if ($showUser || GALLERY_SECURITY=='private') {	//	requires a "user" field
					?>
					<tr>
						<td align="left">
						<h2><?php echo gettext("User"); ?>&nbsp;</h2>
						</td>
						<td><input class="textfield" name="user" id="user" type="text" size="20"
							value="<?php echo html_encode($requestor); ?>" /></td>
					</tr>
					<?php
				}
				?>
				<tr>
					<td align="left">
					<h2><?php echo gettext("Password").$star; ?></h2>
					</td>
					<td><input class="textfield" name="pass" type="password" size="20" /></td>
				</tr>
			</table>
		</fieldset>
		<table class="password">
		<?php
		if ($star == '*') {
			$captchaCode = $_zp_captcha->generateCaptcha($img);
			$html = "<input type=\"hidden\" name=\"code_h\" value=\"" . $captchaCode . "\"/><label><img src=\"" . $img . "\" alt=\"Code\" align=\"middle\"/></label>";
			?>
			<tr>
				<td align="left"><?php echo gettext("*Enter CAPTCHA in place of <em>Password</em> to request a password reset."); ?></td>
				<td><?php echo $html; ?></td>
			</tr>
			<?php
		}
		?>
			<tr>
				<td colspan="2">
					<div class="buttons">
						<button type="submit" value="<?php echo gettext("Log in"); ?>" ><img src="<?php echo WEBPATH.'/'.ZENFOLDER; ?>/images/pass.png" alt="" /><?php echo gettext("Log in"); ?></button>
						<button type="reset" value="<?php echo gettext("Reset"); ?>" ><img src="<?php echo WEBPATH.'/'.ZENFOLDER; ?>/images/reset.png" alt="" /><?php echo gettext("Reset"); ?></button>
					</div>
				</td>
			</tr>
		</table>
		</form>
		<?php
		if ($hint) {
			echo '<p>'.$hint.'</p>';
		}
		?>
		</div>
	<?php
	}

}

class Zenphoto_Administrator extends PersistentObject {

	/**
	 * This is a simple class so that we have a convienient "handle" for manipulating Administrators.
	 *
	 * NOTE: one should use the Zenphoto_Authority newAdministrator() method rather than directly instantiating
	 * an administrator object
	 *
	 */
	var $objects = NULL;
	var $master = false;	//	will be set to true if this is the inherited master user
	var $lastlogon = NULL;	// date of last use
	var $msg = NULL;	//	a means of storing error messages from filter processing

	/**
	 * Constructor for an Administrator
	 *
	 * @param string $user.
	 * @param int $valid used to signal kind of admin object
	 * @return Administrator
	 */
	function Zenphoto_Administrator($user, $valid) {
		global $_zp_authority;
		parent::PersistentObject('administrators', array('user' => $user, 'valid'=>$valid), NULL, false, empty($user));
		if ($valid && $user == $_zp_authority->master_user) {
			$this->setRights($this->getRights() | ADMIN_RIGHTS);
			$this->master = true;
		}
	}

		/**
	 * Returns the unformatted date
	 *
	 * @return date
	 */
	function getDateTime() {
		return $this->get('date');
	}

	/**
	 * Stores the date
	 *
	 * @param string $datetime formatted date
	 */
	function setDateTime($datetime) {
		$this->set('date', $datetime);
	}

	function getID() {
		return $this->get('id');
	}

	/**
	 * Hashes and stores the password
	 * @param $pwd
	 */
	function setPass($pwd) {
		global $_zp_authority;
		$msg = $_zp_authority->validatePassword($pwd);
		if (!empty($msg)) return $msg;	// password validation failure
		$pwd = $_zp_authority->passwordHash($this->getUser(),$pwd);
		$this->set('pass', $pwd);
		return false;
	}
	/**
	 * Returns stored password hash
	 */
	function getPass() {
		return $this->get('pass');
	}

	/**
	 * Stores the user name
	 */
	function setName($admin_n) {
		$this->set('name', $admin_n);
	}
	/**
	 * Returns the user name
	 */
	function getName() {
		return $this->get('name');
	}

	/**
	 * Stores the user email
	 */
	function setEmail($admin_e) {
		$this->set('email', $admin_e);
	}
	/**
	 * Returns the user email
	 */
	function getEmail() {
		return $this->get('email');
	}

	/**
	 * Stores user rights
	 */
	function setRights($rights) {
		$this->set('rights', $rights);
	}
	/**
	 * Returns user rights
	 */
	function getRights() {
		return $this->get('rights');
	}

	/**
	 * Returns local copy of managed objects.
	 */
	function setObjects($objects) {
		$this->objects = $objects;
	}
	/**
	 * Saves local copy of managed objects.
	 * NOTE: The database is NOT updated by this, the user object MUST be saved to
	 * cause an update
	 */
	function getObjects($what=NULL) {
		if (is_null($this->objects)) {
			$this->objects = array();
			if (!$this->transient) {
				$this->objects = populateManagedObjectsList(NULL,$this->getID());
			}
		}
		if (empty($what)) {
			return $this->objects;
		}
		$result = array();
		foreach ($this->objects as $object) {
			if ($object['type'] == $what) {
				$result[] = $object['data'];
			}
		}
		return $result;
	}

	/**
	 * Stores custom data
	 */
	function setCustomData($custom_data) {
		$this->set('custom_data', $custom_data);
	}
	/**
	 * Returns custom data
	 */
	function getCustomData() {
		return $this->get('custom_data');
	}

	/**
	 * Sets the "valid" flag. Valid is 1 for users, 0 for groups and templates
	 */
	function setValid($valid) {
		$this->set('valid', $valid);
	}
	/**
	 * Returns the valid flag
	 */
	function getValid() {
		return $this->get('valid');
	}

	/**
	 * Sets the user's group.
	 * NOTE this does NOT set rights, etc. that must be done separately
	 */
	function setGroup($group) {
		$this->set('group', $group);
	}
	/**
	 * Returns user's group
	 */
	function getGroup() {
		return $this->get('group');
	}

	/**
	 * Sets the user's user id
	 */
	function setUser($user) {
		$this->set('user', $user);
	}
	/**
	 * Returns user's user id
	 */
	function getUser() {
		return $this->get('user');
	}

	/**
	 * Sets the users quota
	 */
	function setQuota($v) {
		$this->set('quota',$v);
	}
	/**
	 * Returns the users quota
	 */
	function getQuota() {
		return $this->get('quota');
	}

	/**
	 * Returns the user's prefered language
	 */
	function getLanguage() {
		return $this->get('language');
	}
	/**
	 * Sets the user's preferec language
	 */
		function setLanguage($locale) {
		$this->set('language',$locale);
	}

	/**
	 * Uptates the database with all changes
	 */
	function save() {
		if (DEBUG_LOGIN) { debugLogVar("Zenphoto_Administrator->save()", $this); }
		$objects = $this->getObjects();
		$gallery = new Gallery();
		if (is_null($this->get('date'))) {
			$this->set('date',date('Y-m-d H:i:s'));
		}
		parent::save();
		$id = $this->getID();
		if (is_array($objects)) {
			$sql = "DELETE FROM ".prefix('admin_to_object').' WHERE `adminid`='.$id;
			$result = query($sql,false);
			if ($result) {
				foreach ($objects as $object) {
					if (array_key_exists('edit',$object)) {
						$edit = $object['edit'] | 32767 & ~(MANAGED_OBJECT_RIGHTS_VIEW_IMAGE |  MANAGED_OBJECT_RIGHTS_EDIT | MANAGED_OBJECT_RIGHTS_UPLOAD);
					} else {
						$edit = 32767;
					}
					switch ($object['type']) {
						case 'album':
							$album = new Album($gallery, $object['data']);
							$albumid = $album->getAlbumID();
							$sql = "INSERT INTO ".prefix('admin_to_object')." (adminid, objectid, type, edit) VALUES ($id, $albumid, 'album', $edit)";
							$result = query($sql);
							break;
						case 'pages':
							$sql = 'SELECT * FROM '.prefix('pages').' WHERE `titlelink`='.db_quote($object['data']);
							$result = query_single_row($sql);
							if (is_array($result)) {
								$objectid = $result['id'];
								$sql = "INSERT INTO ".prefix('admin_to_object')." (adminid, objectid, type, edit) VALUES ($id, $objectid, 'pages', $edit)";
								$result = query($sql);
							}
							break;
						case 'news':
							$sql = 'SELECT * FROM '.prefix('news_categories').' WHERE `titlelink`='.db_quote($object['data']);
							$result = query_single_row($sql);
							if (is_array($result)) {
								$objectid = $result['id'];
								$sql = "INSERT INTO ".prefix('admin_to_object')." (adminid, objectid, type, edit) VALUES ($id, $objectid, 'news', $edit)";
								$result = query($sql);
							}
							break;
					}
				}
			}
		}
	}

	/**
	 * Removes a user from the system
	 */
	function remove() {
		$album = $this->getAlbum();
		$id = $this->getID();
		if (parent::remove()) {
			if (!empty($album)) {	//	Remove users album as well
				$album->remove();
			}
			$sql = "DELETE FROM ".prefix('admin_to_object')." WHERE `adminid`=$id";
			$result = query($sql);
		} else {
			return false;
		}
		return $result;
	}

	/**
	 * Returns the user's "prime" album. See setAlbum().
	 */
	function getAlbum() {
		$id = $this->get('prime_album');
		if (!empty($id)) {
			$sql = 'SELECT `folder` FROM '.prefix('albums').' WHERE `id`='.$id;
			$result = query_single_row($sql);
			if ($result) {
				$album = new Album(new Gallery(), $result['folder']);
				return $album;
			}
		}
		return false;
	}

	/**
	 * Records the "prime album" of a user. Prime albums are linked to the user and
	 * removed if the user is removed.
	 */
	function setAlbum($album) {
		$this->set('prime_album', $album->getID());
	}

	/**
	 * Data to support other credential systems integration
	 */
	function getCredentials() {
		$cred = $this->get('other_credentials');
		if ($cred) {
			return unserialize($cred);
		} else {
			return array();
		}
	}
	function setCredentials($cred) {
		$this->set('other_credentials',serialize($cred));
	}

	/**
	 * Creates a "prime" album for the user. Album name is based on the userid
	 */
	function createPrimealbum() {
		//	create his album
		$t = 0;
		$ext = '';
		$filename = str_replace(array('<', '>', ':', '"'. '/'. '\\', '|', '?', '*'), '_', seoFriendly($this->getUser()));
		while (file_exists(ALBUM_FOLDER_SERVERPATH.'/'.$filename.$ext)) {
			$t++;
			$ext = '-'.$t;
		}
		$path = ALBUM_FOLDER_SERVERPATH.'/'.$filename.$ext;
		if (@mkdir_recursive($path,CHMOD_VALUE)) {
			$album = new Album(new Gallery(), $filename.$ext);
			$album->save();
			$this->setAlbum($album);
			$objects = $this->getObjects();
			$objects[] = array('data'=>$filename.$ext, 'name'=>$filename.$ext, 'type'=>'album');
			$this->setObjects($objects);
		}

	}

	function getChallengePhraseInfo() {
		$info = $this->get('challenge_phrase');
		if ($info) {
			return unserialize($info);
		} else {
			return array('challenge'=>'', 'response'=>'');
		}
	}

	function setChallengePhraseInfo($challenge, $response) {
		$this->set('challenge_phrase', serialize(array('challenge'=>$challenge,'response'=>$response)));
	}

}

?>