<?php
    require_once __DIR__.'/includes/header.inc.php';
?>
        <script>
        (function() {
            try {
                var body = document.body;
                if (!body) { return; }
                body.classList.add('spotinfo', 'spotinfo-page');
                body.classList.remove('fixed');
            } catch (e) {}
        })();
        </script>
<?php
    $spot = $tplHelper->formatSpot($spot);

    // We definieeren hier een aantal settings zodat we niet steeds dezelfde check hoeven uit te voeren
    $show_nzb_button = (
        (!empty($spot['nzb'])) &&
                         ($spot['stamp'] > 1290578400) &&
                         ($tplHelper->allowed(SpotSecurity::spotsec_retrieve_nzb, ''))
    );
    $show_watchlist_button = ($currentSession['user']['prefs']['keep_watchlist'] && $tplHelper->allowed(SpotSecurity::spotsec_keep_own_watchlist, ''));
    $allowedToPost = $tplHelper->allowedToPost();
    $isBlacklisted = ($spot['listidtype'] == 1);
    $isWhitelisted = ($spot['listidtype'] == 2);
    $allow_blackList = (($tplHelper->allowed(SpotSecurity::spotsec_blacklist_spotter, '')) && ($allowedToPost) && (!$isBlacklisted) && (!empty($spot['spotterid'])));
    $allow_whiteList = (($tplHelper->allowed(SpotSecurity::spotsec_blacklist_spotter, '')) && ($allowedToPost) && (!$isBlacklisted) && (!$isWhitelisted) && (!empty($spot['spotterid'])));
    $show_spot_edit = $tplHelper->allowed(SpotSecurity::spotsec_show_spot_was_edited, '');
    $show_editor = $tplHelper->allowed(SpotSecurity::spotsec_view_spot_editor, '');
    $hasWebsite = !empty(trim((string) $spot['website']));
    $hasTag = !empty(trim((string) $spot['tag']));
    $nzbHandlerPrefsUrl = $tplHelper->makeEditUserPrefsUrl($currentSession['user']['userid']);
    $configureNzbHandlerTitle = htmlspecialchars(_('Configure NZB handling before sending this spot to a download client'), ENT_QUOTES);

    /* Determine minimal width of the image, we cannot set it in the CSS because we cannot calculate it there */
    $imgMinWidth = 260;
    if (is_array($spot['image'])) {
        $imgMinWidth = min(260, $spot['image']['width']);
    } // if

?>

		<div id="details" class="details sw-detail <?php echo $tplHelper->cat2CssClass($spot) ?>">
			<form class="blacklistspotterform" name="blacklistspotterform" action="<?php echo $tplHelper->makeListAction(); ?>" method="post">
				<input type="hidden" name="blacklistspotterform[submitaddspotterid]" value="Blacklist">
				<input type="hidden" name="blacklistspotterform[xsrfid]" value="<?php echo $tplHelper->generateXsrfCookie('blacklistspotterform'); ?>">
				<input type="hidden" name="blacklistspotterform[spotterid]" value="<?php echo htmlspecialchars($spot['spotterid']); ?>">
				<input type="hidden" name="blacklistspotterform[origin]" value="Reported via Spotweb for spot <?php echo htmlspecialchars($spot['messageid']); ?>">
				<input type="hidden" name="blacklistspotterform[idtype]" value="1">
			</form>
<?php
    // labels under the title: the category plus every sub category
    $swTags = [];
    foreach (['a', 'b', 'c', 'd', 'z'] as $subcatType) {
        foreach (explode('|', (string) $spot['subcat'.$subcatType]) as $sub) {
            if (!empty($sub)) {
                $swTags[] = [
                    'url'   => $tplHelper->makeSubCatUrl($spot, $sub),
                    'name'  => SpotCategories::Cat2Desc($spot['category'], $sub),
                    'title' => SpotCategories::SubcatDescription($spot['category'], $subcatType),
                ];
            } // if
        } // foreach
    } // foreach
    $swSpotterCount = (int) ($spot['spottercount'] ?? 0);
    $swInitial = mb_strtoupper(mb_substr(trim(strip_tags((string) $spot['poster'])), 0, 1)) ?: '?';
    $swWebsiteHost = $hasWebsite ? (string) parse_url($spot['website'], PHP_URL_HOST) : '';
?>

			<!-- bar: back, previous / next spot -->
			<div class="sw-spotbar">
				<a class="closeDetails" title="<?php echo _('Back to mainview (ESC / U)'); ?>" aria-label="<?php echo _('Back to mainview (ESC / U)'); ?>"><svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="M10 3 5 8l5 5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><span><?php echo _('Back'); ?></span></a>
				<button type="button" class="sw-nav sw-prev" onclick="spotNav('prev')" title="<?php echo _('Previous spot (k)'); ?>" aria-label="<?php echo _('Previous spot (k)'); ?>"><svg viewBox="0 0 14 14" width="14" height="14" aria-hidden="true"><path d="M3 9l4-4 4 4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
				<button type="button" class="sw-nav sw-next" onclick="spotNav('next')" title="<?php echo _('Next spot (j)'); ?>" aria-label="<?php echo _('Next spot (j)'); ?>"><svg viewBox="0 0 14 14" width="14" height="14" aria-hidden="true"><path d="M3 5l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
				<span class="sw-spotpos" data-label="<?php echo htmlspecialchars(_('Spot %1$s of %2$s · j / k to browse'), ENT_QUOTES); ?>"></span>
			</div>

			<div class="sw-spot">
				<!-- hero: poster and the essentials -->
				<div class="sw-hero">
					<div class="sw-poster" style="min-width:<?php echo (int) min(180, $imgMinWidth); ?>px;">
						<a onclick="toggleImageSize()" class="postimage">
							<img class="spotinfoimage" src="<?php echo $tplHelper->makeImageUrl($spot, 260, 260); ?>" alt="<?php echo $spot['title']; ?>">
						</a>
					</div>
					<div class="sw-hero-main">
						<div class="sw-tags">
							<a class="sw-tag sw-tag-cat" href="<?php echo $tplHelper->makeCatUrl($spot); ?>" title="<?php echo _('Find spots in this category'); ?>"><?php echo $spot['catname']; ?></a>
<?php foreach ($swTags as $tag) { ?>
							<a class="sw-tag" href="<?php echo $tag['url']; ?>" title="<?php echo htmlspecialchars($tag['title'], ENT_QUOTES); ?>"><?php echo $tag['name']; ?></a>
<?php } ?>
						</div>
						<h1 class="sw-title"><?php echo $spot['title']; ?></h1>
						<div class="sw-meta">
							<strong><?php echo $tplHelper->format_size($spot['filesize']); ?></strong>
							<span title="<?php echo $tplHelper->formatDate($spot['stamp'], 'force_spotlist'); ?>"><?php echo $tplHelper->formatDate($spot['stamp'], 'spotdetail'); ?></span>
<?php if (!empty($spot['newsreader'])) { ?>
							<span><?php echo htmlspecialchars($spot['newsreader']); ?></span>
<?php } ?>
						</div>
						<div class="sw-trust">
<?php if ($spot['verified']) { ?>
							<span class="sw-chip sw-chip-ok"><svg viewBox="0 0 12 12" width="12" height="12" aria-hidden="true"><path d="M2.5 6.2l2.3 2.3 4.7-5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg><?php echo _('Verified sender'); ?></span>
<?php } else { ?>
							<span class="sw-chip sw-chip-bad" title="<?php echo _('This spot is not verified, the name of the sender has not been confirmed'); ?>"><?php echo _('Not verified'); ?></span>
<?php } ?>
<?php if ($isWhitelisted) { ?>
							<span class="sw-chip sw-chip-ok"><?php echo _('Trusted sender'); ?></span>
<?php } ?>
<?php if ($isBlacklisted) { ?>
							<span class="sw-chip sw-chip-bad"><?php echo _('Blocked sender'); ?></span>
<?php } ?>
<?php if ($tplHelper->isModerated($spot)) { ?>
							<span class="sw-chip sw-chip-bad"><?php echo _('Marked as possible spam'); ?></span>
<?php } ?>
							<span class="sw-chip<?php echo ($spot['reportcount'] > 0) ? ' sw-chip-bad' : ''; ?>"><?php echo sprintf(ngettext('%d spam report', '%d spam reports', (int) $spot['reportcount']), (int) $spot['reportcount']); ?></span>
<?php if ($spot['rating'] > 0) { ?>
							<span class="sw-chip sw-chip-star" title="<?php echo sprintf(ngettext('This spot thas %d star', 'This spot has %d stars', $spot['rating']), $spot['rating']); ?>">&#9733; <?php echo (int) $spot['rating']; ?> / 10</span>
<?php } ?>
						</div>
					</div>
				</div>

				<!-- one action bar, the main action first -->
				<div class="sw-actions">
<?php if ($show_nzb_button) { ?>
<?php if (!empty($spot['sabnzbdurl'])) { ?>
					<a onclick="downloadSabnzbd(<?php echo "'".$spot['id']."','".$spot['sabnzbdurl']."','".$spot['nzbhandlertype']."'"; ?>)" class="sab_<?php echo $spot['id']; ?> sabnzbd-button sw-btn sw-btn-primary<?php echo $spot['hasbeendownloaded'] ? ' succes' : ''; ?>" title="<?php echo $spot['hasbeendownloaded'] ? _('Add NZB to SABnzbd queue (you already downloaded this spot) (s)') : _('Add NZB to SABnzbd queue (s)'); ?>"><svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="M8 2.5v8M4.5 7l3.5 3.5L11.5 7M3 13.5h10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="sw-label"><?php echo _('Send to SABnzbd'); ?></span><kbd>S</kbd></a>
<?php } else { ?>
					<a href="<?php echo $nzbHandlerPrefsUrl; ?>" onclick="return promptNzbHandlerSetup(event, this.href)" class="sabnzbd-button unconfigured sw-btn" title="<?php echo $configureNzbHandlerTitle; ?>"><?php echo _('Set up SABnzbd'); ?></a>
<?php } ?>
					<a class="nzb sw-btn<?php echo empty($spot['sabnzbdurl']) ? ' sw-btn-primary' : ''; ?><?php echo $spot['hasbeendownloaded'] ? ' downloaded' : ''; ?>" href="<?php echo $tplHelper->makeNzbUrl($spot); ?>" title="<?php echo _('Download NZB'); echo $spot['hasbeendownloaded'] ? ' '._('(this spot has already been downloaded)') : ''; ?> (n)"><?php echo _('Download NZB'); ?><kbd>N</kbd></a>
<?php } ?>
<?php if ($show_watchlist_button) { ?>
					<span class="watch">
						<a class="remove watchremove_<?php echo $spot['id']; ?> sw-btn" onclick="toggleWatchSpot('<?php echo $spot['messageid']; ?>','remove',<?php echo $spot['id']; ?>)"<?php echo $spot['isbeingwatched'] ? '' : ' style="display: none;"'; ?> title="<?php echo _('Delete from watchlist (w)'); ?>">&#9733;<span class="sw-label"> <?php echo _('Watching'); ?></span></a>
						<a class="add watchadd_<?php echo $spot['id']; ?> sw-btn" onclick="toggleWatchSpot('<?php echo $spot['messageid']; ?>','add',<?php echo $spot['id']; ?>)"<?php echo $spot['isbeingwatched'] ? ' style="display: none;"' : ''; ?> title="<?php echo _('Place in watchlist (w)'); ?>">&#9734;<span class="sw-label"> <?php echo _('Watch'); ?></span></a>
					</span>
<?php } ?>
					<a class="sw-btn sw-search" href="<?php echo $spot['searchurl']; ?>" rel="nofollow" title="<?php echo _('Find NZB'); ?>"><?php echo _('Search'); ?> &#8599;</a>
<?php if ($tplHelper->allowed(SpotSecurity::spotsec_report_spam, '') && ($currentSession['user']['userid'] > 2)) { ?>
<?php if (!$tplHelper->isReportPlaced($spot['messageid'])) { ?>
					<a onclick="openDialog('editdialogdiv', '<?php echo _('Report spam'); ?>', '?page=render&tplname=reportspot&data[messageid]=<?php echo urlencode($spot['messageid']); ?>', postReportForm, 'autoclose', null, null);" class="spamreport-button sw-btn sw-btn-quiet" title="<?php echo _('Report this spot as spam'); ?>"><?php echo _('Report spam'); ?></a>
<?php } else { ?>
					<a onclick="return false;" class="spamreport-button success sw-btn sw-btn-quiet" title="<?php echo _('You already reported this spot as spam'); ?>"><?php echo _('Reported'); ?></a>
<?php } ?>
<?php } ?>
				</div>

				<!-- sender -->
				<div class="sw-sender">
					<span class="sw-avatar" aria-hidden="true"><?php echo htmlspecialchars($swInitial); ?></span>
					<div class="sw-sender-name">
						<a href="<?php echo $tplHelper->makePosterUrl($spot); ?>" title="<?php echo sprintf(_('Find spots from %s'), $spot['poster']); ?>"><?php echo $spot['poster']; ?></a>
						<span><?php if (!empty($spot['spotterid'])) { ?><code><?php echo $spot['spotterid']; ?></code> · <?php } ?><?php echo sprintf(ngettext('%s spot', '%s spots', $swSpotterCount), number_format($swSpotterCount, 0, ',', '.')); ?></span>
					</div>
					<div class="sw-sender-actions">
<?php if (!empty($spot['spotterid'])) { ?>
						<a class="sw-mini" href="<?php echo $tplHelper->makeSpotterIdUrl($spot); ?>"><?php echo _('All spots'); ?></a>
<?php } ?>
<?php if ((!empty($spot['spotterid'])) && ($tplHelper->allowed(SpotSecurity::spotsec_keep_own_filters, ''))) { ?>
						<a href="" class="addspotterasfilter sw-mini" title="<?php echo _('Add filter for this spotter'); ?>" onclick="addSpotFilter('<?php echo $tplHelper->generateXsrfCookie('editfilterform'); ?>', 'SpotterID', '<?php echo urlencode($spot['spotterid']); ?>', 'Zoek spots van &quot;<?php echo urlencode($spot['poster']); ?>&quot;', 'addspotterasfilter'); return false; ">+ <?php echo _('Filter'); ?></a>
<?php } ?>
<?php if ($allow_whiteList) { ?>
						<a class="whitelist blacklistuserlink_<?php echo htmlspecialchars($spot['spotterid']); ?> sw-mini" title="<?php echo _('Whitelist this sender'); ?>" onclick="whitelistSpotterId('<?php echo htmlspecialchars($spot['spotterid']); ?>');"><?php echo _('Trust'); ?></a>
<?php } ?>
<?php if ($allow_blackList) { ?>
						<a class="delete blacklistuserlink_<?php echo htmlspecialchars($spot['spotterid']); ?> sw-mini" title="<?php echo _('Blacklist this sender'); ?>" onclick="blacklistSpotterId('<?php echo htmlspecialchars($spot['spotterid']); ?>');"><?php echo _('Block'); ?></a>
<?php } ?>
					</div>
				</div>

				<!-- tabs instead of one long page -->
				<div class="sw-tabs" role="tablist">
					<button type="button" class="sw-tab active" role="tab" aria-selected="true" data-sw-tab="description"><?php echo _('Description'); ?></button>
<?php if ($tplHelper->allowed(SpotSecurity::spotsec_view_comments, '')) { ?>
					<button type="button" class="sw-tab" role="tab" aria-selected="false" data-sw-tab="comments"><?php echo _('Comments'); ?> <span class="commentcount"></span></button>
<?php } ?>
					<button type="button" class="sw-tab" role="tab" aria-selected="false" data-sw-tab="details"><?php echo _('All details'); ?></button>
				</div>

				<div class="sw-panel active" data-sw-panel="description">
					<div class="description">
						<pre><?php echo $spot['description']; ?></pre>
					</div>
<?php if ($hasWebsite) { ?>
					<a class="sw-linkcard" href="<?php echo $spot['website']; ?>" rel="nofollow">
						<span class="sw-linkcard-label"><?php echo _('Website'); ?></span>
						<strong><?php echo htmlspecialchars($swWebsiteHost !== '' ? preg_replace('/^www\./', '', $swWebsiteHost) : _('Website')); ?> &#8599;</strong>
						<span class="sw-linkcard-url"><?php echo $spot['website']; ?></span>
					</a>
<?php } ?>
				</div>

<?php if ($tplHelper->allowed(SpotSecurity::spotsec_view_comments, '')) { ?>
				<div class="sw-panel" data-sw-panel="comments">
					<div class="comments" id="comments">
						<ul id="commentslist">
<?php
if ($tplHelper->allowed(SpotSecurity::spotsec_post_comment, '')) {
    if ($currentSession['user']['userid'] > 2) {
        echo "<li class='addComment'>";
        echo "<a class='togglePostComment' title='"._('Add comment (open/close windows)')."'>"._('Add comment').'<span></span></a><div><div></div>';
        require __DIR__.'/postcomment.inc.php';
        echo '</div></li>';
    }
} ?>
						</ul>
					</div>
				</div>
<?php } ?>

				<div class="sw-panel" data-sw-panel="details">
					<table class="spotinfo">
						<tbody>
							<tr><th><?php echo _('Category'); ?></th><td><a href="<?php echo $tplHelper->makeCatUrl($spot); ?>"><?php echo $spot['catname']; ?></a></td></tr>
<?php foreach ($swTags as $tag) { ?>
							<tr><th><?php echo htmlspecialchars($tag['title']); ?></th><td><a href="<?php echo $tag['url']; ?>"><?php echo $tag['name']; ?></a></td></tr>
<?php } ?>
							<tr><th><?php echo _('Date'); ?></th><td><?php echo $tplHelper->formatDate($spot['stamp'], 'force_spotlist'); ?></td></tr>
							<tr><th><?php echo _('Size'); ?></th><td><?php echo $tplHelper->format_size($spot['filesize']); ?></td></tr>
<?php if (!empty($spot['newsreader'])) { ?>
							<tr><th><?php echo _('Spotted with'); ?></th><td><?php echo htmlspecialchars($spot['newsreader']); ?></td></tr>
<?php } ?>
<?php if ($hasWebsite) { ?>
							<tr><th><?php echo _('Website'); ?></th><td><a href="<?php echo $spot['website']; ?>" rel="nofollow"><?php echo $spot['website']; ?></a></td></tr>
<?php } ?>
							<tr><th><?php echo _('Sender'); ?></th><td><?php echo $spot['poster']; ?><?php echo !empty($spot['spotterid']) ? ' ('.$spot['spotterid'].')' : ''; ?></td></tr>
<?php if ($hasTag) { ?>
							<tr><th><?php echo _('Tag'); ?></th><td><a href="<?php echo $tplHelper->makeTagUrl($spot); ?>"><?php echo $spot['tag']; ?></a>
<?php if ($tplHelper->allowed(SpotSecurity::spotsec_keep_own_filters, '')) { ?>
								<a href="#" class="addtagasfilter sw-mini" title="<?php echo _('Add filter for this tag'); ?>" onclick="addSpotFilter('<?php echo $tplHelper->generateXsrfCookie('editfilterform'); ?>', 'Tag', '<?php echo urlencode($spot['tag']); ?>', 'Zoek op tag &quot;<?php echo urlencode($spot['tag']); ?>&quot;', 'addtagasfilter'); return false; ">+ <?php echo _('Filter'); ?></a>
<?php } ?>
							</td></tr>
<?php } ?>
							<tr><th><?php echo _('Number of spamreports'); ?></th><td><?php echo (int) $spot['reportcount']; ?></td></tr>
<?php if ($show_spot_edit && $spot['editstamp']) { ?>
							<tr><th><?php echo _('Spot edited'); ?></th><td><?php echo $tplHelper->formatDate($spot['editstamp'], 'force_spotlist'); ?><?php echo $show_editor ? ' ('.$spot['editor'].')' : ''; ?></td></tr>
<?php } ?>
							<tr><th><?php echo _('Message-ID'); ?></th><td><code><?php echo htmlspecialchars($spot['messageid']); ?></code></td></tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		
		<input type="hidden" id="messageid" value="<?php echo $spot['messageid'] ?>" />
<?php
require_once __DIR__.'/includes/footer.inc.php';
?>

<script type="text/javascript">
    $(document).ready(function(){
        // Attach an onLoad() listener to the image so we can bring the image into view
        loadSpotImage();

        $("#details").addClass("external");

        $("a[href^='http']").attr('target','_blank');

        $("a.closeDetails").click(function(){
            window.close();
        });

        var messageid = $('#messageid').val();
        postCommentsForm();
        postBlacklistForm();
        if (spotweb_retrieve_commentsperpage > 0) {
            loadComments(messageid,spotweb_retrieve_commentsperpage,'0');
        } // if
    });

    function addText(text,element_id) {
        document.getElementById(element_id).value += text;
    }
</script>
