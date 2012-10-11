<?
class Users {
	/**
	 * Get $Classes (list of classes keyed by ID) and $ClassLevels
	 *		(list of classes keyed by level)
	 * @return array ($Classes, $ClassLevels)
	 */
	public static function get_classes() {
		global $Cache, $DB, $Debug;
		// Get permissions
		list($Classes, $ClassLevels) = $Cache->get_value('classes');
		if (!$Classes || !$ClassLevels) {
			$DB->query('SELECT ID, Name, Level, Secondary FROM permissions ORDER BY Level');
			$Classes = $DB->to_array('ID');
			$ClassLevels = $DB->to_array('Level');
			$Cache->cache_value('classes', array($Classes, $ClassLevels), 0);
		}
		$Debug->set_flag('Loaded permissions');
		
		return array($Classes, $ClassLevels);
	}


	/**
	 * Get user info, is used for the current user and usernames all over the site.
	 *
	 * @param $UserID int   The UserID to get info for
	 * @return array with the following keys:
	 *	int	ID
	 *	string	Username
	 *	int	PermissionID
	 *	array	Paranoia - $Paranoia array sent to class_paranoia
	 *	boolean	Artist
	 *	boolean	Donor
	 *	string	Warned - When their warning expires in international time format
	 *	string	Avatar - URL
	 *	boolean	Enabled
	 *	string	Title
	 *	string	CatchupTime - When they last caught up on forums
	 *	boolean	Visible - If false, they don't show up on peer lists
	 *	array ExtraClasses - Secondary classes.
	 *	int EffectiveClass - the highest level of their main and secondary classes
	 */
	public static function user_info($UserID) {
		global $DB, $Cache, $Classes, $SSL;
		$UserInfo = $Cache->get_value('user_info_'.$UserID);
		// the !isset($UserInfo['Paranoia']) can be removed after a transition period
		if (empty($UserInfo) || empty($UserInfo['ID']) || !isset($UserInfo['Paranoia']) || empty($UserInfo['Class'])) {
			$OldQueryID = $DB->get_query_id();
	
	
			$DB->query("SELECT
				m.ID,
				m.Username,
				m.PermissionID,
				m.Paranoia,
				i.Artist,
				i.Donor,
				i.Warned,
				i.Avatar,
				m.Enabled,
				m.Title,
				i.CatchupTime,
				m.Visible,
				GROUP_CONCAT(ul.PermissionID SEPARATOR ',') AS Levels
				FROM users_main AS m
				INNER JOIN users_info AS i ON i.UserID=m.ID
				LEFT JOIN users_levels AS ul ON ul.UserID = m.ID
				WHERE m.ID='$UserID'
				GROUP BY m.ID");
			if ($DB->record_count() == 0) { // Deleted user, maybe?
				$UserInfo = array('ID'=>'','Username'=>'','PermissionID'=>0,'Artist'=>false,'Donor'=>false,'Warned'=>'0000-00-00 00:00:00','Avatar'=>'','Enabled'=>0,'Title'=>'', 'CatchupTime'=>0, 'Visible'=>'1');
	
			} else {
				$UserInfo = $DB->next_record(MYSQLI_ASSOC, array('Paranoia', 'Title'));
				$UserInfo['CatchupTime'] = strtotime($UserInfo['CatchupTime']);
				$UserInfo['Paranoia'] = unserialize($UserInfo['Paranoia']);
				if ($UserInfo['Paranoia'] === false) {
					$UserInfo['Paranoia'] = array();
				}
			}


			$UserInfo['Class'] = $Classes[$UserInfo['PermissionID']]['Level'];

			if (!empty($UserInfo['Levels'])) {
				$UserInfo['ExtraClasses'] = array_fill_keys(explode(',', $UserInfo['Levels']), 1);
			} else {
				$UserInfo['ExtraClasses'] = array();
			}
			unset($UserInfo['Levels']);
			$EffectiveClass = $Classes[$UserInfo['PermissionID']]['Level'];
			foreach ($UserInfo['ExtraClasses'] as $Class => $Val) {
				$EffectiveClass = max($EffectiveClass, $Classes[$Class]['Level']);
			}
			$UserInfo['EffectiveClass'] = $EffectiveClass;

			$Cache->cache_value('user_info_'.$UserID, $UserInfo, 2592000);
			$DB->set_query_id($OldQueryID);
		}
		if (strtotime($UserInfo['Warned']) < time()) {
			$UserInfo['Warned'] = '0000-00-00 00:00:00';
			$Cache->cache_value('user_info_'.$UserID, $UserInfo, 2592000);
		}

		// Image proxy
		if (check_perms('site_proxy_images') && !empty($UserInfo['Avatar'])) {
			$UserInfo['Avatar'] = 'http'.($SSL?'s':'').'://'.SITE_URL.'/image.php?c=1&amp;avatar='.$UserID.'&amp;i='.urlencode($UserInfo['Avatar']);
		}
		return $UserInfo;
	}

	/**
	 * Gets the heavy user info
	 * Only used for current user
	 *
	 * @param $UserID The userid to get the information for
	 * @return fetched heavy info.
	 *		Just read the goddamn code, I don't have time to comment this shit.
	 */
	public static function user_heavy_info($UserID) {
		global $DB, $Cache;
		//global $Debug;

		$HeavyInfo = $Cache->get_value('user_info_heavy_'.$UserID);
	
		if (empty($HeavyInfo)) {
	
			$DB->query("SELECT
				m.Invites,
				m.torrent_pass,
				m.IP,
				m.CustomPermissions,
				m.can_leech AS CanLeech,
				i.AuthKey,
				i.RatioWatchEnds,
				i.RatioWatchDownload,
				i.StyleID,
				i.StyleURL,
				i.DisableInvites,
				i.DisablePosting,
				i.DisableUpload,
				i.DisableWiki,
				i.DisableAvatar,
				i.DisablePM,
				i.DisableRequests,
				i.DisableForums,
				i.SiteOptions,
				i.DownloadAlt,
				i.LastReadNews,
				i.LastReadBlog,
				i.RestrictedForums,
				i.PermittedForums,
				m.FLTokens,
				m.PermissionID
				FROM users_main AS m
				INNER JOIN users_info AS i ON i.UserID=m.ID
				WHERE m.ID='$UserID'");
			$HeavyInfo = $DB->next_record(MYSQLI_ASSOC, array('CustomPermissions', 'SiteOptions'));

			if (!empty($HeavyInfo['CustomPermissions'])) {
				$HeavyInfo['CustomPermissions'] = unserialize($HeavyInfo['CustomPermissions']);
			} else {
				$HeavyInfo['CustomPermissions'] = array();
			}

			if (!empty($HeavyInfo['RestrictedForums'])) {
				$RestrictedForums = array_map('trim', explode(',', $HeavyInfo['RestrictedForums']));
			} else {
				$RestrictedForums = array();
			}
			unset($HeavyInfo['RestrictedForums']);
			if (!empty($HeavyInfo['PermittedForums'])) {
				$PermittedForums = array_map('trim', explode(',', $HeavyInfo['PermittedForums']));
			} else {
				$PermittedForums = array();
			}
			unset($HeavyInfo['PermittedForums']);
			//$Debug->log_var($PermittedForums, 'PermittedForums - User');

			$DB->query("SELECT PermissionID FROM users_levels WHERE UserID = $UserID");
			$PermIDs = $DB->collect('PermissionID');
			foreach ($PermIDs AS $PermID) {
				$Perms = Permissions::get_permissions($PermID);
				if (!empty($Perms['PermittedForums'])) {
					//$Debug->log_var("'".$Perms['PermittedForums']."'", "PermittedForums - Perm $PermID");
					$PermittedForums = array_merge($PermittedForums, array_map('trim', explode(',',$Perms['PermittedForums'])));
					//$Debug->log_var($PermittedForums, "PermittedForums - After Perm $PermID");
				}
			}
			$Perms = Permissions::get_permissions($HeavyInfo['PermissionID']);
			unset($HeavyInfo['PermissionID']);
			if (!empty($Perms['PermittedForums'])) {
				$PermittedForums = array_merge($PermittedForums, array_map('trim', explode(',',$Perms['PermittedForums'])));
			}
			//$Debug->log_var($PermittedForums, 'PermittedForums - Done');

			if (!empty($PermittedForums) || !empty($RestrictedForums)) {
				$HeavyInfo['CustomForums'] = array();
				foreach ($RestrictedForums as $ForumID) {
					$HeavyInfo['CustomForums'][$ForumID] = 0;
				}
				foreach ($PermittedForums as $ForumID) {
					$HeavyInfo['CustomForums'][$ForumID] = 1;
				}
			} else {
				$HeavyInfo['CustomForums'] = null;
			}
			//$Debug->log_var($HeavyInfo['CustomForums'], 'CustomForums');

			$HeavyInfo['SiteOptions'] = unserialize($HeavyInfo['SiteOptions']);
			if (!empty($HeavyInfo['SiteOptions'])) {
				$HeavyInfo = array_merge($HeavyInfo, $HeavyInfo['SiteOptions']);
			}
			unset($HeavyInfo['SiteOptions']);

			$Cache->cache_value('user_info_heavy_'.$UserID, $HeavyInfo, 0);
		}
		return $HeavyInfo;
	}

	/**
	 * Updates the site options in the database
	 *
	 * @param int $UserID the UserID to set the options for
	 * @param array $NewOptions the new options to set
	 * @return false if $NewOptions is empty, true otherwise
	 */
	public static function update_site_options($UserID, $NewOptions) {
		if (!is_number($UserID)) {
			error(0);
		}
		if (empty($NewOptions)) {
			return false;
		}
		global $DB, $Cache, $LoggedUser;

		// Get SiteOptions
		$DB->query("SELECT SiteOptions FROM users_info WHERE UserID = $UserID");
		list($SiteOptions) = $DB->next_record(MYSQLI_NUM,false);
		$SiteOptions = unserialize($SiteOptions);

		// Get HeavyInfo
		$HeavyInfo = Users::user_heavy_info($UserID);

		// Insert new/replace old options
		$SiteOptions = array_merge($SiteOptions, $NewOptions);
		$HeavyInfo = array_merge($HeavyInfo, $NewOptions);

		// Update DB
		$DB->query("UPDATE users_info SET SiteOptions = '".db_string(serialize($SiteOptions))."' WHERE UserID = $UserID");

		// Update cache
		$Cache->cache_value('user_info_heavy_'.$UserID, $HeavyInfo, 0);

		// Update $LoggedUser if the options are changed for the current
		if ($LoggedUser['ID'] == $UserID) {
			$LoggedUser = array_merge($LoggedUser, $NewOptions);
			$LoggedUser['ID'] = $UserID; // We don't want to allow userid switching
		}
		return true;
	}


	/**
	 * Generate a random string
	 *
	 * @param Length
	 * @return random alphanumeric string
	 */
	public static function make_secret($Length = 32) {
		$Secret = '';
		$Chars='abcdefghijklmnopqrstuvwxyz0123456789';
		$CharLen = strlen($Chars)-1;
		for ($i = 0; $i < $Length; ++$i) {
			$Secret .= $Chars[mt_rand(0, $CharLen)];
		}
		return $Secret;
	}

	/**
	 * Create a password hash. This method is deprecated and
	 * should not be used to create new passwords
	 *
	 * @param $Str password
	 * @param $Secret salt
	 * @return password hash
	 */
	public static function make_hash($Str,$Secret) {
		return sha1(md5($Secret).$Str.sha1($Secret).SITE_SALT);
	}

	/**
	 * Verify a password against a password hash
	 *
	 * @param $Password password
	 * @param $Hash password hash
	 * @param $Secret salt - Only used if the hash was created
	 *		       with the deprecated Users::make_hash() method
	 * @return true on correct password
	 */
	public static function check_password($Password, $Hash, $Secret='') {
		if (!$Password || !$Hash) {
			return false;
		}
		if (Users::is_crypt_hash($Hash)) {
			return crypt($Password, $Hash) == $Hash;
		} elseif ($Secret) {
			return Users::make_hash($Password, $Secret) == $Hash;
		}
		return false;
	}

	/**
	 * Test if a given hash is a crypt hash
	 *
	 * @param $Hash password hash
	 * @return true if hash is a crypt hash
	 */
	public static function is_crypt_hash($Hash) {
		return preg_match('/\$\d[axy]?\$/', substr($Hash, 0, 4));
	}

	/**
	 * Create salted crypt hash for a given string with
	 * settings specified in CRYPT_HASH_PREFIX
	 *
	 * @param $Str string to hash
	 * @return salted crypt hash
	 */
	public static function make_crypt_hash($Str) {
		$Salt = CRYPT_HASH_PREFIX.Users::gen_crypt_salt().'$';
		return crypt($Str, $Salt);
	}

	/**
	 * Create salt string for eksblowfish hashing. If /dev/urandom cannot be read,
	 * fall back to an unsecure method based on mt_rand(). The last character needs
	 * a special case as it must be either '.', 'O', 'e', or 'u'.
	 *
	 * @return salt suitable for eksblowfish hashing
	 */
	public static function gen_crypt_salt() {
		$Salt = '';
		$Chars = "./ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
		$Numchars = strlen($Chars) - 1;
		if ($Handle = @fopen('/dev/urandom', 'r')) {
			$Bytes = fread($Handle, 22);
			for($i = 0; $i < 21; $i++) {
				$Salt .= $Chars[ord($Bytes[$i]) & $Numchars];
			}
			$Salt[$i] = $Chars[(ord($Bytes[$i]) & 3) << 4];
		} else {
			for($i = 0; $i < 21; $i++) {
				$Salt .= $Chars[mt_rand(0, $Numchars)];
			}
			$Salt[$i] = $Chars[mt_rand(0, 3) << 4];
		}
		return $Salt;
	}

	/**
	 * Returns a username string for display
	 *
	 * @param int $UserID
	 * @param boolean $Badges whether or not badges (donor, warned, enabled) should be shown
	 * @param boolean $IsWarned -- TODO: Why the fuck do we need this?
	 * @param boolean $IsEnabled -- TODO: Why the fuck do we need this?
	 * @param boolean $Class whether or not to show the class
	 * @param boolean $Title whether or not to show the title
	 * @return HTML formatted username
	 */
	public static function format_username($UserID, $Badges = false, $IsWarned = true, $IsEnabled = true, $Class = false, $Title = false) {
		global $Classes;

		// This array is a hack that should be made less retarded, but whatevs
		// 						  PermID => ShortForm
		$SecondaryClasses = array(
								 );

		if ($UserID == 0) {
			return 'System';
		}

		$UserInfo = Users::user_info($UserID);
		if ($UserInfo['Username'] == '') {
			return "Unknown [$UserID]";
		}

		$Str = '';

		if ($Title) {
			$Str .= '<strong><a href="user.php?id='.$UserID.'">'.$UserInfo['Username'].'</a></strong>';
		} else {
			$Str .= '<a href="user.php?id='.$UserID.'">'.$UserInfo['Username'].'</a>';
		}

		if ($Badges) {
			$Str .= ($UserInfo['Donor'] == 1) ? '<a href="donate.php"><img src="'.STATIC_SERVER.'common/symbols/donor.png" alt="Donor" title="Donor" /></a>' : '';
		}
		$Str .= ($IsWarned && $UserInfo['Warned'] != '0000-00-00 00:00:00') ? '<a href="wiki.php?action=article&amp;id=218"><img src="'.STATIC_SERVER.'common/symbols/warned.png" alt="Warned" title="Warned" /></a>' : '';
		$Str .= ($IsEnabled && $UserInfo['Enabled'] == 2) ? '<a href="rules.php"><img src="'.STATIC_SERVER.'common/symbols/disabled.png" alt="Banned" title="Be good, and you won\'t end up like this user" /></a>' : '';

		if ($Badges) {
			$ClassesDisplay = array();
			foreach($SecondaryClasses as $PermID => $PermHTML) {
				if ($UserInfo['ExtraClasses'][$PermID]) {
					$ClassesDisplay[] = '<span class="secondary_class" title="'.$Classes[$PermID]['Name'].'">'.$PermHTML.'</span>';
				}
			}
			if (!empty($ClassesDisplay)) {
				$Str .= '&nbsp;'.implode('&nbsp;', $ClassesDisplay);
			}
		}

		if ($Class) {
			if ($Title) {
				$Str .= ' <strong>('.Users::make_class_string($UserInfo['PermissionID']).')</strong>';
			} else {
				$Str .= ' ('.Users::make_class_string($UserInfo['PermissionID']).')';
			}
		}

		if ($Title) {
			// Image proxy CTs
			if (check_perms('site_proxy_images') && !empty($UserInfo['Title'])) {
				$UserInfo['Title'] = preg_replace_callback('~src=("?)(http.+?)(["\s>])~',
					function($Matches) {
						return 'src='.$Matches[1].'http'.($SSL?'s':'').'://'.SITE_URL.'/image.php?c=1&amp;i='.urlencode($Matches[2]).$Matches[3];
					},
					$UserInfo['Title']);
			}

			if ($UserInfo['Title']) {
				$Str .= ' <span class="user_title">('.$UserInfo['Title'].')</span>';
			}
		}
		return $Str;
	}

	/**
	 * Given a class ID, return its name.
	 *
	 * @param int $ClassID
	 * @return string name
	 */
	public static function make_class_string($ClassID) {
		global $Classes;
		return $Classes[$ClassID]['Name'];
	}
}
?>
