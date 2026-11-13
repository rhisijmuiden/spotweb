<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html>
	<head>
		<meta http-equiv='Content-Type' content='text/html; charset=utf-8'>
		<title>SpotWeb - <?php echo $pagetitle?></title>
		<meta name="generator" content="SpotWeb v<?php echo SPOTWEB_VERSION; ?>">
<?php if ($settings->get('deny_robots')) {
    echo "\t\t<meta name=\"robots\" content=\"noindex, nofollow\">\r\n";
} ?>
		<base href='<?php echo $tplHelper->makeBaseUrl('full'); ?>'>
<?php if ($tplHelper->allowed(SpotSecurity::spotsec_view_rssfeed, '')) { ?>
		<link rel='alternate' type='application/rss+xml' href='<?php echo $tplHelper->makeRssUrl(); ?>'>
<?php } ?>

		<script type="text/javascript">
		// Guards for legacy globals some plugins expect
		try { if (typeof window.templates === 'undefined') { window.templates = {}; } } catch (e) { }
		try { if (typeof window.modern === 'undefined') { window.modern = {}; } } catch (e) { }
<?php
    $swThemeNames = [];
    foreach (Services_Admin_Themes::getThemes() as $swThemeId => $swTheme) {
        $swThemeNames[$swThemeId] = $swTheme['name'];
    } // foreach
?>
		// Colour theme: the admin picks the site theme, a visitor may override it in the toolbar
		window.SpotwebThemes = {
		  site: <?php echo json_encode(Services_Admin_Themes::getSiteTheme($settings)); ?>,
		  modes: <?php echo json_encode(Services_Admin_Themes::getModes()); ?>,
		  names: <?php echo json_encode($swThemeNames, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		  label: <?php echo json_encode(_('Theme'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		  siteLabel: <?php echo json_encode(_('Site default'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
		  getPref: function () {
		    var p = null;
		    try { p = localStorage.getItem('spotweb_theme'); } catch (e) {}
		    if (p === 'light') { p = 'daylight'; } else if (p === 'dark') { p = 'midnight'; }
		    return (p && this.modes[p]) ? p : 'site';
		  },
		  resolve: function (pref) {
		    var id = (pref === 'site' || !this.modes[pref]) ? this.site : pref;
		    var mode = this.modes[id];
		    if (mode === 'auto') {
		      mode = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
		      return { id: id, mode: mode, palette: mode === 'dark' ? 'midnight' : 'daylight' };
		    }
		    return { id: id, mode: mode, palette: id };
		  },
		  apply: function (pref) {
		    var t = this.resolve(pref);
		    document.documentElement.setAttribute('data-theme', t.mode);
		    document.documentElement.setAttribute('data-palette', t.palette);
		    return t;
		  }
		};
		try { window.SpotwebThemes.apply(window.SpotwebThemes.getPref()); } catch (e) {}
		// Fallback for back button in full-page spot view
		document.addEventListener('click', function(ev){
		  var a = ev.target && ev.target.closest ? ev.target.closest('a.closeDetails') : null;
		  if (!a) return;
		  ev.preventDefault();
		  try {
		    if (window.history.length > 1) { window.history.back(); }
		    else { window.location.href = '<?php echo $tplHelper->makeBaseUrl('path'); ?>'; }
		  } catch(e) { window.location.href = '<?php echo $tplHelper->makeBaseUrl('path'); ?>'; }
		}, true);
		</script>

<?php if ($tplHelper->allowed(SpotSecurity::spotsec_view_statics, '')) { ?>
		<link rel='stylesheet' type='text/css' href='?page=statics&amp;type=css&amp;mod=<?php echo $tplHelper->getStaticModTime('css'); ?>'>
		<link rel='shortcut icon' href='?page=statics&amp;type=ico&amp;mod=<?php echo $tplHelper->getStaticModTime('ico'); ?>'>
<?php } ?>
<?php foreach ($tplHelper->getThemeHeaderCssFiles() as $cssFile) {
    // a version per file, so browsers and Cloudflare pick up a changed stylesheet after an update
    $cssMtime = @filemtime(__DIR__.'/../../../'.ltrim(parse_url($cssFile, PHP_URL_PATH), '/'));
    $cssHref = $cssMtime ? $cssFile.((strpos($cssFile, '?') === false) ? '?' : '&').'v='.$cssMtime : $cssFile; ?>
		<link rel='stylesheet' type='text/css' href='<?php echo htmlspecialchars($cssHref, ENT_QUOTES, 'UTF-8'); ?>'>
<?php } ?>
		<style type="text/css" media="screen,handheld,projection">
			<?php echo $settings->get('customcss'); ?>
		</style>
<?php if ($tplHelper->allowed(SpotSecurity::spotsec_allow_custom_stylesheet, '')) { ?>
		<style type="text/css" media="screen,handheld,projection">
			<?php echo $tplHelper->getUserCustomCss(); ?>
		</style>
<?php } ?>
		<!-- Modern JS (loaded separately to avoid statics concatenation issues) -->
<?php foreach ($tplHelper->getThemeHeaderJsFiles() as $jsFile) {
    $jsMtime = @filemtime(__DIR__.'/../../../'.ltrim(parse_url($jsFile, PHP_URL_PATH), '/'));
    $jsSrc = $jsMtime ? $jsFile.((strpos($jsFile, '?') === false) ? '?' : '&').'v='.$jsMtime : $jsFile; ?>
		<script src="<?php echo htmlspecialchars($jsSrc, ENT_QUOTES, 'UTF-8'); ?>" type="text/javascript" defer></script>
<?php } ?>
<?php
    $pageName = strtolower((string) ($_GET['page'] ?? 'index'));
    $tplName = strtolower((string) ($_GET['tplname'] ?? ''));
    $bodyClasses = [
        'modern-theme',
        'modern-page-'.preg_replace('/[^a-z0-9_-]+/', '-', $pageName),
    ];
    $configPages = ['editsettings', 'edituserprefs', 'usermanagement'];
    $configTemplates = ['usermanagement', 'listusers', 'listgroups', 'listfilters', 'editfilter'];
    if (in_array($pageName, $configPages, true) || in_array($tplName, $configTemplates, true)) {
        $bodyClasses[] = 'modern-config';
    }
?>
	</head>
	<body class="<?php echo htmlspecialchars(implode(' ', $bodyClasses), ENT_QUOTES, 'UTF-8'); ?>">
		<div id='editdialogdiv'></div>
		<div id="overlay"></div>
		<div class="container" id="container">
