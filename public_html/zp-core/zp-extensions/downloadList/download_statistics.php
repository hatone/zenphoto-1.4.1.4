<?php
/**
 * Detailed Gallery Statistics
 *
 * This plugin shows statistical graphs and info about your gallery\'s images and albums
 *
 * This plugin is dependent on the css of the gallery_statistics utility plugin!
 *
 * @package admin
 */

define('OFFSET_PATH', 4);
require_once(dirname(dirname(dirname(__FILE__))).'/admin-globals.php');

$button_text = gettext('Download Statistics');
$button_hint = gettext("Shows statistical graphs and info about your gallery's downloads if using the downloadList plugin.");
$button_icon = WEBPATH.'/'.ZENFOLDER.'/images/bar_graph.png';
$button_rights = ADMIN_RIGHTS;

admin_securityChecks(ADMIN_RIGHTS, currentRelativeURL(__FILE__));

if (getOption('zenphoto_release') != ZENPHOTO_RELEASE) {
	header("Location: " . FULLWEBPATH . "/" . ZENFOLDER . "/setup.php");
	exit();
}

if (!zp_loggedin(OVERVIEW_RIGHTS)) { // prevent nefarious access to this page.
	header('Location: ' . FULLWEBPATH . '/' . ZENFOLDER . '/admin.php?from=' . currentRelativeURL(__FILE__));
	exit();
}

$gallery = new Gallery();
$webpath = WEBPATH.'/'.ZENFOLDER.'/';

printAdminHeader(gettext('utilities'),gettext('download statistics'));
?>
<link rel="stylesheet" href="<?php echo WEBPATH.'/'.ZENFOLDER; ?>/admin-statistics.css" type="text/css" media="screen" />
<?php
/**
 * Prints a table with a bar graph of the values.
 */
function printBarGraph() {
	global $gallery, $webpath;
	//$limit = $from_number.",".$to_number;
	$bargraphmaxsize = 400;
	$items = query_full_array("SELECT `aux`,`data`FROM ".prefix('plugin_storage')." WHERE `type` = 'downloadList' AND `data` != 0 ORDER BY `data` DESC");
	if($items) {
		$maxvalue = $items[0]['data'];
		$no_statistic_message = "";
	} else {
		$no_statistic_message = "<tr><td><em>".gettext("No statistic available")."</em></td><td></td><td></td><td></td></tr>";
	}

	$countlines = 0;
	echo "<table class='bordered'>";
	echo "<tr><th colspan='4'><strong>".gettext("Most downloaded files")."</strong>";
	echo "</th></tr>";
	$count = '';
	echo $no_statistic_message;
	foreach ($items as $item) {
		if($item['data'] != 0) {
			$count++;
			$barsize = round($item['data'] / $maxvalue * $bargraphmaxsize);
			$value = $item['data'];

			// counter to have a gray background of every second line
			if($countlines === 1) {
				$style = " style='background-color: #f4f4f4'";	// a little ugly but the already attached class for the table is so easiest overriden...
				$countlines = 0;
			} else {
				$style = "";
				$countlines++;
			}
			$outdated = '';
			if(!file_exists(internalToFilesystem(SERVERPATH.'/'.$item['aux']))) {
				$outdated = ' class="unpublished_item"';
			}
			?>
			<tr class="statistic_wrapper">
			<td class="statistic_counter" <?php echo $style; ?>>
			<?php echo $count; ?>
			</td>
			<td class="statistic_title" <?php echo $style; ?>>
			<strong<?php echo $outdated; ?>>
			<?php echo html_encode($item['aux']); ?>
			</strong>
			</td>
			<td class="statistic_graphwrap" <?php echo $style; ?>>
			<div class="statistic_bargraph" style="width: <?php echo $barsize; ?>px"></div>
			<div class="statistic_value"><?php echo $value; ?></div>
			</td>
			</tr>
			<?php
		} // if value != 0

	} // foreach end
		?>
		</table>
<?php
}
echo '</head>';
?>

<body>
<?php printLogoAndLinks(); ?>
<div id="main">
<a name="top"></a>
<?php printTabs('home');
?>
<div id="content">
	<?php
	if(isset($_GET['removeoutdateddownloads'])) {
		XSRFdefender('removeoutdateddownloads');
		$sql = "SELECT * FROM ".prefix('plugin_storage')." WHERE `type` = 'downloadList'";
		$result = query_full_array($sql);
		if ($result) {
			foreach ($result as $row) {
				if (!file_exists(internalToFilesystem($row['aux']))) {
					query('DELETE FROM '.prefix('plugin_storage').' WHERE `id`='.$row['id']);
				}
			}
		}
		echo '<p class="messagebox fade-message">'.gettext('Outdated file entries cleared from the database').'</p>';
	}
	if(isset($_GET['removealldownloads'])) {
		XSRFdefender('removealldownloads');
		$sql = "DELETE FROM ".prefix('plugin_storage')." WHERE `type` = type='downloadList'";
		query($sql);
		echo '<p class="messagebox fade-message">'.gettext('All download file entries cleared from the database').'</p>';
	}
	?>
<h1><?php echo gettext("Download Statistics"); ?></h1>
<p><?php echo gettext("Shows statistical graphs and info about your gallery's downloads if using the downloadList plugin."); ?></p>
<p><?php echo gettext("Entries marked red do not exist in the download folder anymore but are kept for the statistics until you remove them manually via the button."); ?></p>

<?php
if(!getOption('zp_plugin_downloadList')) {
	echo '<strong>'.gettext('The downloadList plugin is not active').'</strong>';
} else {
	?>
	<p class="buttons"><a href="?removeoutdateddownloads&amp;XSRFToken=<?php echo getXSRFToken('removeoutdateddownloads')?>"><?php echo gettext('Clear outdated downloads from database'); ?></a></p>
	<p class="buttons"><a href="?removealldownloads&amp;XSRFToken=<?php echo getXSRFToken('removealldownloads')?>"><?php echo gettext('Clear all downloads from database'); ?></a></p><br clear="all" />
	<br clear="all" /><br />
  <?php
	printBarGraph();
}
?>


</div><!-- content -->
<?php printAdminFooter(); ?>
</div><!-- main -->
</body>
<?php echo "</html>"; ?>