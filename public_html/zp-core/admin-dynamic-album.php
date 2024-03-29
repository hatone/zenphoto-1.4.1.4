<?php
/**
 * This script is used to create dynamic albums from a search.
 * @package core
 */

// force UTF-8 Ø

define('OFFSET_PATH', 1);
require_once(dirname(__FILE__).'/admin-globals.php');
require_once(dirname(__FILE__).'/template-functions.php');

admin_securityChecks(ALBUM_RIGHTS, $return = currentRelativeURL(__FILE__));

$imagelist = array();
$gallery = new Gallery();

function getSubalbumImages($folder) {
	global $imagelist, $gallery;
	if (hasDynamicAlbumSuffix($folder)) { return; }
	$album = new Album($gallery, $folder);
	$images = $album->getImages();
	foreach ($images as $image) {
		$imagelist[] = '/'.$folder.'/'.$image;
	}
	$albums = $album->getAlbums();
	foreach ($albums as $folder) {
		getSubalbumImages($folder);
	}
}

$search = new SearchEngine(true);
if (isset($_POST['savealbum'])) {
	XSRFdefender('savealbum');
	$albumname = sanitize($_POST['album']);
	$album = sanitize($_POST['albumselect']);
	$albumobj = new Album($gallery, $album);
	if (!$albumobj->isMyItem(ALBUM_RIGHTS)) {
		if (!zp_apply_filter('admin_managed_albums_access',false, $return)) {
			die(gettext("You do not have edit rights on this album."));
		}
	}
	$words = sanitize($_POST['words']);
	if (isset($_POST['thumb'])) {
		$thumb = sanitize($_POST['thumb']);
	} else {
		$thumb = '';
	}
	$searchfields = array();
	foreach ($_POST as $key=>$value) {
		if (strpos($key, 'SEARCH_') !== false) {
			$searchfields[] = sanitize(str_replace('SEARCH_', '', postIndexDecode($key)));
		}
	}
	$constraints = "\nCONSTRAINTS=".'inalbums='.((int) (isset($_POST['return_albums']))).'&inimages='.((int) (isset($_POST['return_images'])));
	$redirect = $album.'/'.$albumname.".alb";

	if (!empty($albumname)) {
		$f = fopen(internalToFilesystem(ALBUM_FOLDER_SERVERPATH.$redirect), 'w');
		if ($f !== false) {
			fwrite($f,"WORDS=$words\nTHUMB=$thumb\nFIELDS=".implode(',',$searchfields).$constraints."\n");
			fclose($f);
			clearstatcache();
			// redirct to edit of this album
			header("Location: " . FULLWEBPATH . "/" . ZENFOLDER . "/admin-edit.php?page=edit&album=" . pathurlencode($redirect));
			exit();
		}
	}
}
$_GET['page'] = 'edit'; // pretend to be the edit page.
printAdminHeader('edit',gettext('dynamic'));
echo "\n</head>";
echo "\n<body>";
printLogoAndLinks();
echo "\n" . '<div id="main">';
printTabs();
echo "\n" . '<div id="content">';
zp_apply_filter('admin_note','albums', 'dynamic');
echo "<h1>".gettext("zenphoto Create Dynamic Album")."</h1>\n";

if (isset($_POST['savealbum'])) { // we fell through, some kind of error
	echo "<div class=\"errorbox space\">";
	echo "<h2>".gettext("Failed to save the album file")."</h2>";
	echo "</div>\n";
}

$albumlist = array();
genAlbumUploadList($albumlist);
$params = trim(zp_getCookie('zenphoto_search_params'));
$search->setSearchParams($params);
$fields = $search->fieldList;
$albumname = $words = $search->codifySearchString();
$images = $search->getImages(0);
foreach ($images as $image) {
	$folder = $image['folder'];
	$filename = $image['filename'];
	$imagelist[] = '/'.$folder.'/'.$filename;
}
$subalbums = $search->getAlbums(0);
foreach ($subalbums as $folder) {
	getSubalbumImages($folder);
}
$albumname = sanitize_path($albumname);
$albumname = seoFriendly($albumname);
$old = '';
while ($old != $albumname) {
	$old = $albumname;
	$albumname = str_replace('--', '-', $albumname);
}
?>
<form action="?savealbum" method="post">
	<?php XSRFToken('savealbum');?>
<input type="hidden" name="savealbum" value="yes" />
<table>
	<tr>
		<td><?php echo gettext("Album name:"); ?></td>
		<td><input type="text" size="40" name="album"
			value="<?php echo html_encode($albumname) ?>" /></td>
	</tr>
	<tr>
		<td><?php echo gettext("Create in:"); ?></td>
		<td><select id="albumselectmenu" name="albumselect">
		<?php
		if (accessAllAlbums(UPLOAD_RIGHTS)) {
			?>
			<option value="" selected="selected" style="font-weight: bold;">/</option>
			<?php
}
$bglevels = array('#fff','#f8f8f8','#efefef','#e8e8e8','#dfdfdf','#d8d8d8','#cfcfcf','#c8c8c8');
foreach ($albumlist as $fullfolder => $albumtitle) {
	$singlefolder = $fullfolder;
	$saprefix = "";
	$salevel = 0;
	// Get rid of the slashes in the subalbum, while also making a subalbum prefix for the menu.
	while (strstr($singlefolder, '/') !== false) {
		$singlefolder = substr(strstr($singlefolder, '/'), 1);
		$saprefix = "&nbsp; &nbsp;&raquo;&nbsp;" . $saprefix;
		$salevel++;
	}
	echo '<option value="' . $fullfolder . '"' . ($salevel > 0 ? ' style="background-color: '.$bglevels[$salevel].'; border-bottom: 1px dotted #ccc;"' : '')
	. ">" . $saprefix . $singlefolder . " (" . $albumtitle . ')' . "</option>\n";
}
?>
		</select></td>
	</tr>
	<tr>
		<td><?php echo gettext("Thumbnail:"); ?></td>
		<td><select id="thumb" name="thumb">
		<?php
		$showThumb = $gallery->getThumbSelectImages();
		echo "\n<option";
		if ($showThumb) echo " class=\"thumboption\" value=\"\" style=\"background-color:#B1F7B6\"";
		echo ' value="1">'.get_language_string(getOption('AlbumThumbSelectorText'));
		echo '</option>';
		echo "\n<option";
		if ($showThumb) echo " class=\"thumboption\" value=\"\" style=\"background-color:#B1F7B6\"";
		echo " selected=\"selected\"";
		echo ' value="">'.gettext('randomly selected');
		echo '</option>';
		foreach ($imagelist as $imagepath) {
			$pieces = explode('/', $imagepath);
			$filename = array_pop($pieces);;
			$folder = implode('/', $pieces);
			$albumx = new Album($gallery, $folder);
			$image = newImage($albumx, $filename);
			if (isImagePhoto($image) || !is_null($image->objectsThumb)) {
				echo "\n<option class=\"thumboption\"";
				if ($showThumb) {
					echo " style=\"background-image: url(" . html_encode($image->getSizedImage(80)) .
									"); background-repeat: no-repeat;\"";
				}
				echo " value=\"".$imagepath."\"";
				echo ">" . $image->getTitle();
				echo  " ($imagepath)";
				echo "</option>";
			}
		}
		?>
		</select></td>
	</tr>
	<tr>
		<td><?php echo gettext("Search criteria:"); ?></td>
		<td>
			<input type="text" size="60" name="words" value="<?php echo html_encode($words); ?>" />
			<label><input type="checkbox" name="return_albums" value="1"<?php if (!getOption('search_no_albums')) echo ' checked="checked"'?> /><?php echo gettext('Return albums found')?></label>
			<label><input type="checkbox" name="return_images" value="1"<?php if (!getOption('search_no_images')) echo ' checked="checked"'?> /><?php echo gettext('Return images found')?></label>
		</td>
	</tr>
	<tr>
		<td><?php echo gettext("Search fields:"); ?></td>
		<td>
		<?php
		echo '<ul class="searchchecklist">'."\n";
		$selected_fields = array();
		$engine = new SearchEngine(true);
		$available_fields = $engine->allowedSearchFields();
		if (count($fields)==0) {
			$selected_fields = $available_fields;
		} else {
			foreach ($available_fields as $display=>$key) {
				if (in_array($key,$fields)) {
					$selected_fields[$display] = $key;
				}
			}
		}

		generateUnorderedListFromArray($selected_fields, $available_fields, 'SEARCH_', false, true, true);
		echo '</ul>';
		?>
		</td>
	</tr>

</table>

<input type="submit" value="<?php echo gettext('Create the album');?>" class="button" />
</form>

<?php

echo "\n" . '</div>';
echo "\n" . '</div>';

printAdminFooter();

echo "\n</body>";
echo "\n</html>";
?>

