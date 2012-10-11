<?
/* 
This page is something of a hack so those 
easily scared off by funky solutions, don't 
touch it! :P

There is a central problem to this page, it's 
impossible to order before grouping in SQL, and
it's slow to run sub queries, so we had to get 
creative for this one.

The solution I settled on abuses the way 
$DB->to_array() works. What we've done, is 
backwards ordering. The results returned by the
query have the best one for each GroupID last, 
and while to_array traverses the results, it 
overwrites the keys and leaves us with only the
desired result. This does mean however, that 
the SQL has to be done in a somewhat backwards 
fashion.

Thats all you get for a disclaimer, just 
remember, this page isn't for the faint of 
heart. -A9

SQL template:
SELECT 
	CASE 
	WHEN t.Format='Ogg Vorbis' THEN 0 
	WHEN t.Format='MP3' AND t.Encoding='V0 (VBR)' THEN 1 
	WHEN t.Format='MP3' AND t.Encoding='V2 (VBR)' THEN 2 
	ELSE 100 
	END AS Rank, 
	t.GroupID,
	t.Media,
	t.Format,
	t.Encoding,
	IF(t.Year=0,tg.Year,t.Year),
	tg.Name,
	a.Name,
	t.Size
FROM torrents AS t 
INNER JOIN collages_torrents AS c ON t.GroupID=c.GroupID AND c.CollageID='8'
INNER JOIN torrents_group AS tg ON tg.ID=t.GroupID AND tg.CategoryID='1'
LEFT JOIN artists_group AS a ON a.ArtistID=tg.ArtistID
LEFT JOIN torrents_files AS f ON t.ID=f.TorrentID
ORDER BY t.GroupID ASC, Rank DESC, t.Seeders ASC
*/

if(
	!isset($_REQUEST['collageid']) || 
	!isset($_REQUEST['preference']) || 
	!is_number($_REQUEST['preference']) || 
	!is_number($_REQUEST['collageid']) || 
	$_REQUEST['preference'] > 2 ||
	count($_REQUEST['list']) == 0
) { error(0); }

if(!check_perms('zip_downloader')){ error(403); }

$Preferences = array('RemasterTitle DESC','Seeders ASC','Size ASC');

$CollageID = $_REQUEST['collageid'];
$Preference = $Preferences[$_REQUEST['preference']];

$DB->query("SELECT Name FROM collages WHERE ID='$CollageID'");
list($CollageName) = $DB->next_record(MYSQLI_NUM,false);

$SQL = "SELECT CASE ";

foreach ($_REQUEST['list'] as $Priority => $Selection) {
	if(!is_number($Priority)) {
		continue;
	}
	$SQL .= "WHEN ";
	switch ($Selection) {
		case '00': $SQL .= "t.Format='MP3' AND t.Encoding='V0 (VBR)'"; break;
		case '01': $SQL .= "t.Format='MP3' AND t.Encoding='APX (VBR)'"; break;
		case '02': $SQL .= "t.Format='MP3' AND t.Encoding='256 (VBR)'"; break;
		case '03': $SQL .= "t.Format='MP3' AND t.Encoding='V1 (VBR)'"; break;
		case '10': $SQL .= "t.Format='MP3' AND t.Encoding='224 (VBR)'"; break;
		case '11': $SQL .= "t.Format='MP3' AND t.Encoding='V2 (VBR)'"; break;
		case '12': $SQL .= "t.Format='MP3' AND t.Encoding='APS (VBR)'"; break;
		case '13': $SQL .= "t.Format='MP3' AND t.Encoding='192 (VBR)'"; break;
		case '20': $SQL .= "t.Format='MP3' AND t.Encoding='320'"; break;
		case '21': $SQL .= "t.Format='MP3' AND t.Encoding='256'"; break;
		case '22': $SQL .= "t.Format='MP3' AND t.Encoding='224'"; break;
		case '23': $SQL .= "t.Format='MP3' AND t.Encoding='192'"; break;
		case '30': $SQL .= "t.Format='FLAC' AND t.Encoding='24bit Lossless' AND t.Media='Vinyl'"; break;
		case '31': $SQL .= "t.Format='FLAC' AND t.Encoding='24bit Lossless' AND t.Media='DVD'"; break;
		case '32': $SQL .= "t.Format='FLAC' AND t.Encoding='24bit Lossless' AND t.Media='SACD'"; break;
		case '33': $SQL .= "t.Format='FLAC' AND t.Encoding='24bit Lossless' AND t.Media='WEB'"; break;
		case '34': $SQL .= "t.Format='FLAC' AND t.Encoding='Lossless' AND HasLog='1' AND LogScore='100' AND HasCue='1'"; break;
		case '35': $SQL .= "t.Format='FLAC' AND t.Encoding='Lossless' AND HasLog='1' AND LogScore='100'"; break;
		case '36': $SQL .= "t.Format='FLAC' AND t.Encoding='Lossless' AND HasLog='1'"; break;
		case '37': $SQL .= "t.Format='FLAC' AND t.Encoding='Lossless'"; break;
		case '40': $SQL .= "t.Format='DTS'"; break;
		case '41': $SQL .= "t.Format='Ogg Vorbis'"; break;
		case '42': $SQL .= "t.Format='AAC' AND t.Encoding='320'"; break;
		case '43': $SQL .= "t.Format='AAC' AND t.Encoding='256'"; break;
		case '44': $SQL .= "t.Format='AAC' AND t.Encoding='q5.5'"; break;
		case '45': $SQL .= "t.Format='AAC' AND t.Encoding='q5'"; break;
		case '46': $SQL .= "t.Format='AAC' AND t.Encoding='192'"; break;
		default: error(0);
	}
	$SQL .= " THEN $Priority ";
}
$SQL .= "ELSE 100 END AS Rank,
t.GroupID,
t.ID,
t.Media,
t.Format,
t.Encoding,
IF(t.RemasterYear=0,tg.Year,t.RemasterYear),
tg.Name,
t.Size
FROM torrents AS t 
INNER JOIN collages_torrents AS c ON t.GroupID=c.GroupID AND c.CollageID='$CollageID'
INNER JOIN torrents_group AS tg ON tg.ID=t.GroupID AND tg.CategoryID='1'
ORDER BY t.GroupID ASC, Rank DESC, t.$Preference";

$DB->query($SQL);
$Downloads = $DB->to_array('1',MYSQLI_NUM,false);
$Artists = Artists::get_artists($DB->collect('GroupID'), false);
$Skips = array();
$TotalSize = 0;

if(count($Downloads)) {
	foreach($Downloads as $Download) {
		$TorrentIDs[] = $Download[2];
	}
	$DB->query("SELECT TorrentID, file FROM torrents_files WHERE TorrentID IN (".implode(',', $TorrentIDs).")");
	$Torrents = $DB->to_array('TorrentID',MYSQLI_ASSOC,false);
}

require(SERVER_ROOT.'/classes/class_torrent.php');
require(SERVER_ROOT.'/classes/class_zip.php');
$Zip = new ZIP(Misc::file_string($CollageName));
foreach($Downloads as $Download) {
	list($Rank, $GroupID, $TorrentID, $Media, $Format, $Encoding, $Year, $Album, $Size) = $Download;
	$Artist = Artists::display_artists($Artists[$GroupID],false,true,false);
	if ($Rank == 100) {
		$Skips[] = $Artist.$Album.' '.$Year;
		continue;
	}
	$TotalSize += $Size;
	$Contents = unserialize(base64_decode($Torrents[$TorrentID]['file']));
	$Tor = new TORRENT($Contents, true);
	$Tor->set_announce_url(ANNOUNCE_URL.'/'.$LoggedUser['torrent_pass'].'/announce');
	unset($Tor->Val['announce-list']);
	
	// We need this section for long file names :/
	$TorrentName='';
	$TorrentInfo='';
	$TorrentName = Misc::file_string($Artist.$Album);
	if ($Year   >   0) { $TorrentName.=' - '.Misc::file_string($Year); }
	if ($Media  != '') { $TorrentInfo .= Misc::file_string($Media); }
	if ($Format != '') {
		if ($TorrentInfo!='') { $TorrentInfo .= ' - '; }
		$TorrentInfo .= Misc::file_string($Format);
	}
	if ($Encoding!='') {
		if ($TorrentInfo != '') { $TorrentInfo.=' - '; }
		$TorrentInfo .= Misc::file_string($Encoding);
	}
	if ($TorrentInfo != '') { $TorrentInfo = " ($TorrentInfo)"; }
	if (strlen($TorrentName) + strlen($TorrentInfo) + 3 > 200) {
		$TorrentName = Misc::file_string($Album).(($Year>0)?(' - '.Misc::file_string($Year)):'');
	}
	$FileName = $TorrentName.$TorrentInfo;
	$FileName = Format::cut_string($FileName, 192, true, false);
	
	$Zip->add_file($Tor->enc(), $FileName.'.torrent');
}
$Analyzed = count($Downloads);
$Skipped = count($Skips);
$Downloaded = $Analyzed - $Skipped;
$Time = number_format(((microtime(true)-$ScriptStartTime)*1000),5).' ms';
$Used = Format::get_size(memory_get_usage(true));
$Date = date('M d Y, H:i');
$Zip->add_file('Collector Download Summary - '.SITE_NAME."\r\n\r\nUser:\t\t$LoggedUser[Username]\r\nPasskey:\t$LoggedUser[torrent_pass]\r\n\r\nTime:\t\t$Time\r\nUsed:\t\t$Used\r\nDate:\t\t$Date\r\n\r\nTorrents Analyzed:\t\t$Analyzed\r\nTorrents Filtered:\t\t$Skipped\r\nTorrents Downloaded:\t$Downloaded\r\n\r\nTotal Size of Torrents (Ratio Hit): ".Format::get_size($TotalSize)."\r\n\r\nAlbums Unavailable within your criteria (consider making a request for your desired format):\r\n".implode("\r\n",$Skips), 'Summary.txt');
$Settings = array(implode(':',$_REQUEST['list']),$_REQUEST['preference']);
$Zip->close_stream();

$Settings = array(implode(':',$_REQUEST['list']),$_REQUEST['preference']);
if(!isset($LoggedUser['Collector']) || $LoggedUser['Collector'] != $Settings) {
	$DB->query("SELECT SiteOptions FROM users_info WHERE UserID='$LoggedUser[ID]'");
	list($Options) = $DB->next_record(MYSQLI_NUM,false);
	$Options = unserialize($Options);
	$Options['Collector'] = $Settings;
	$DB->query("UPDATE users_info SET SiteOptions='".db_string(serialize($Options))."' WHERE UserID='$LoggedUser[ID]'");
	$Cache->begin_transaction('user_info_heavy_'.$LoggedUser['ID']);
	$Cache->insert('Collector',$Settings);
	$Cache->commit_transaction(0);
}

define('IE_WORKAROUND_NO_CACHE_HEADERS', 1);
?>
