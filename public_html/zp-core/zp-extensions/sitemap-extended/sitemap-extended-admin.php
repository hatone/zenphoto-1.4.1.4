<?php
/**
 * Sitemap Tools
 *
 * Tool to generate sitemaps
 *
 * @package admin
 */

define('OFFSET_PATH', 4);
chdir(dirname(dirname(__FILE__)));

// user plugin variant
require_once('../../zp-core/admin-globals.php');
require_once('../../zp-core/template-functions.php');

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
printAdminHeader(gettext('utilities'),gettext('Sitemap tools'));
if(isset($_GET['generatesitemaps'])) {
	$sitemap_number = sanitize_numeric($_GET['number']);
	$sitemap_index = getSitemapIndexLinks();
	$sitemap_albums = getSitemapAlbums();
	$sitemap_images = getSitemapImages();
	if(getOption('zp_plugin_zenpage')) {
		$sitemap_newsindex = getSitemapZenpageNewsIndex();
		$sitemap_articles = getSitemapZenpageNewsArticles();
		$sitemap_categories = getSitemapZenpageNewsCategories();
		$sitemap_pages = getSitemapZenpagePages();
	}
	$numberAppend = '';
	if(isset($_GET['generatesitemaps']) &&
	(!empty($sitemap_index)
	|| !empty($sitemap_albums)
	|| !empty($sitemap_images)
	|| !empty($sitemap_newsindex)
	|| !empty($sitemap_articles)
	|| !empty($sitemap_categories)
	|| !empty($sitemap_pages))) {
		$numberAppend = '-'.$sitemap_number;
		$metaURL = 'sitemap-extended-admin.php?generatesitemaps&amp;number='.($sitemap_number+SITEMAP_CHUNK);
	} else {
		$metaURL = '';
	}
	if (!empty($metaURL)) {
		?>
		<meta http-equiv="refresh" content="1; url=<?php echo $metaURL; ?>" />
		<?php
	}
} // if(isset($_GET['generatesitemaps']) end
?>
<link rel="stylesheet" href="<?php echo WEBPATH.'/'.ZENFOLDER; ?>/admin-statistics.css" type="text/css" media="screen" />
<script type="text/javascript">
		// <!-- <![CDATA[
		$(document).ready(function(){
			$(".colorbox").colorbox({
				iframe: false
			});
		});
		// ]]> -->
	</script>
<?php
echo '</head>';

	function sitemap_printAvailableSitemaps() {
		$cachefolder = SERVERPATH.'/cache_html/sitemap/';
		$dirs = array_diff(scandir($cachefolder),array( '.', '..','.DS_Store','Thumbs.db','.htaccess','.svn'));
		echo '<h2>'.gettext('Available sitemap files:').'</h2>';
		if(!$dirs) {
			echo '<p>'.gettext('No sitemap files available.').'</p>';
		} else {
			echo '<ol>';
			foreach($dirs as $dir) {
				$filemtime = filemtime($cachefolder.$dir);
				$lastchange = zpFormattedDate(DATE_FORMAT,$filemtime);
				echo '<li>'.$dir.' (<small>'.$lastchange.')</small>'; //<a class="colorbox" href="'.FULLWEBPATH.'/cache_html/sitemap/'.$dir.'">Preview</a></li>';
			}
			echo '</ol>';
		}
	}
?>

<body>
<?php printLogoAndLinks(); ?>
<div id="main">
<a name="top"></a>
<?php printTabs('home');
?>
<div id="content">
<?php zp_apply_filter('admin_note','sitemap', ''); ?>
	<h1>Sitemap tools</h1>
<?php if(!isset($_GET['generatesitemaps']) && !isset($_GET['clearsitemapcache'])) { ?>
	<p class="notebox"><?php echo gettext('<strong>NOTE:</strong> If your theme uses different custom settings instead of the backend options the sitemaps may not match your site.'); ?></p>
	<p><?php echo gettext('This creates individual static xml sitemap files of the following items:'); ?></p>
	<ul>
		<li><strong><?php echo gettext('Zenphoto items'); ?></strong>
			<ul>
				<li><em><?php echo gettext('Index pages'); ?></em></li>
				<li><?php echo gettext('<em>Albums</em>: These are split into multiple sitemaps.'); ?></li>
				<li><?php echo gettext('<em>Images</em>: These are split into multiple sitemaps.'); ?></li>
			</ul>
		</li>
		<li><strong><?php echo gettext('Zenpage CMS items (if the plugin is enabled)'); ?></strong>
			<ul>
				<li><em><?php echo gettext('News index'); ?></em></li>
				<li><em><?php echo gettext('News Articles'); ?></em></li>
				<li><em><?php echo gettext('News categories'); ?></em></li>
				<li><em><?php echo gettext('Pages'); ?></em></li>
			</ul>
		</li>
	</ul>
	<p><?php echo gettext('Additionally a sitemapindex file is created that points to the separate ones above. You can reference this sitemapindex file in your robots.txt file or submit its url to services like Google via <code>www.yourdomain.com/zenphoto/index.php?sitemap</code>'); ?></p>
	<p><?php echo gettext('The sitemap cache is cleared if you create new ones. All files are stored in the <code>/cache_html/sitemap/</code> folder.'); ?></p>
	<p class="buttons"><a href="sitemap-extended-admin.php?generatesitemaps&amp;number=1"><?php echo "Generate sitemaps"; ?></a></p>
	<p class="buttons"><a href="sitemap-extended-admin.php?clearsitemapcache"><?php echo "Clear sitemap cache"; ?></a></p>
	<br style="clear: both" /><br />
	<?php sitemap_printAvailableSitemaps();
	} // isset generate sitemaps / clearsitemap cache
	if(isset($_GET['generatesitemaps'])) {
		// clear cache before creating new ones
		if($sitemap_number == 1) {
			clearSitemapCache();
		}
		echo '<ul>';
		generateSitemapCacheFile('sitemap-zenphoto-index',$sitemap_index);
	  generateSitemapCacheFile('sitemap-zenphoto-albums'.$numberAppend,$sitemap_albums);
		generateSitemapCacheFile('sitemap-zenphoto-images'.$numberAppend,$sitemap_images);
		if(getOption('zp_plugin_zenpage')) {
			generateSitemapCacheFile('sitemap-zenpage-newsindex',$sitemap_newsindex);
			generateSitemapCacheFile('sitemap-zenpage-news',$sitemap_articles);
			generateSitemapCacheFile('sitemap-zenpage-categories',$sitemap_categories);
			generateSitemapCacheFile('sitemap-zenpage-pages',$sitemap_pages);
		}
		echo '</ul>';
		if(!empty($metaURL)) {
			echo '<p><img src="../../images/ajax-loader.gif" alt="" /><br /><br />'.gettext('Sitemap files are being generated...Patience please.').'</p>';
		} else {
			generateSitemapIndexCacheFile();
		 ?>
		<p><?php echo gettext('Finished!'); ?></p>
		<p class="buttons"><a href="sitemap-extended-admin.php"><?php echo 'Back to Sitemap tools'; ?></a></p>
		<?php
		}
	}
	if(isset($_GET['clearsitemapcache'])) {
		clearSitemapCache();
		echo gettext('Sitemap cache cleared');
		?>
		<p class="buttons"><a href="sitemap-extended-admin.php"><?php echo 'Back to Sitemap tools'; ?></a></p>
		<?php
	}

	?>

</div><!-- content -->
<?php printAdminFooter(); ?>
</div><!-- main -->
</body>
<?php echo "</html>"; ?>