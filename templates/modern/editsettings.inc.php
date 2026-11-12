<?php

require_once __DIR__.'/../../lib/services/Nntp/Services_Nntp_Encryption.php';

require __DIR__.'/includes/header.inc.php';
require __DIR__.'/includes/form-messages.inc.php';

if ($result->isSubmitted()) {
    if ($result->isSuccess()) {
        $tplHelper->redirect($http_referer);

        return;
    } else {
        showResults($result, ['renderhtml' => 1]);
    } // else
} // if

    $nntp_nzb = $this->_settings->get('nntp_nzb');
    $nntp_hdr = $this->_settings->get('nntp_hdr');
    $nntp_post = $this->_settings->get('nntp_post');
    $smtp = $this->_settings->get('smtp');

    if (!isset($nntp_nzb['verifyname'])) {
        $nntp_nzb['verifyname'] = true;
    }
    if (!isset($nntp_nzb['article_pipeline_depth'])) {
        $nntp_nzb['article_pipeline_depth'] = 32;
    }
    if (!isset($nntp_hdr['verifyname'])) {
        $nntp_hdr['verifyname'] = true;
    }
    if (!isset($nntp_hdr['article_pipeline_depth'])) {
        $nntp_hdr['article_pipeline_depth'] = 32;
    }
    if (!isset($nntp_post['verifyname'])) {
        $nntp_post['verifyname'] = true;
    }
    if (!isset($nntp_post['article_pipeline_depth'])) {
        $nntp_post['article_pipeline_depth'] = 32;
    }

    $tmpArDiff = strcmp(serialize($nntp_hdr), serialize($nntp_nzb));
    if ((empty($tmpArDiff)) || (empty($nntp_hdr['host']))) {
        $nntp_hdr['isadummy'] = true;
    } // if

    $tmpArDiff = strcmp(serialize($nntp_post), serialize($nntp_nzb));
    if ((empty($tmpArDiff)) || (empty($nntp_post['host']))) {
        $nntp_post['isadummy'] = true;
    } // if

    $retrieve_newer_than = $this->_settings->get('retrieve_newer_than');
    if ($retrieve_newer_than < 1254373200) {
        $retrieve_newer_than = 1254373200; // 2009-11-01
    } // if
    echo "<script type='text/javascript'>var retrieveNewerThanDate = '".date('d-m-y', $retrieve_newer_than)."';</script>";
?>
</div>
	<div id='toolbar'>
		<div class="closeeditsettings"><p><a class='toggle' href='<?php echo $tplHelper->makeBaseUrl('path'); ?>'><?php echo _('Back to mainview'); ?></a></p></div>
	</div>
<form class="editsettingsform" name="editsettingsform" action="<?php echo $tplHelper->makeEditSettingsAction(); ?>" method="post" enctype="multipart/form-data">
	<input type="hidden" name="editsettingsform[xsrfid]" value="<?php echo $tplHelper->generateXsrfCookie('editsettingsform'); ?>">
	<input type="hidden" name="editsettingsform[http_referer]" value="<?php echo $http_referer; ?>">
	
	<div id="editsettingstab" class="ui-tabs sw-admin">
		<ul class="sw-admin-nav">
			<li class="sw-admin-title"><?php echo _('Admin'); ?></li>
<?php if ($tplHelper->allowed(SpotSecurity::spotsec_edit_settings, '')) { ?>
			<li class="sw-admin-heading"><?php echo _('Site'); ?></li>
			<li><a href="#editsettingstab-1"><span><?php echo _('General'); ?></span></a></li>
			<li><a href="#editsettingstab-6"><span><?php echo _('Users &amp; registration'); ?></span></a></li>
			<li><a href="#editsettingstab-5"><span><?php echo _('Appearance'); ?></span></a></li>
			<li class="sw-admin-heading"><?php echo _('Usenet'); ?></li>
			<li><a href="#editsettingstab-2"><span><?php echo _('News servers'); ?></span></a></li>
			<li><a href="#editsettingstab-3"><span><?php echo _('Retrieval &amp; cleanup'); ?></span></a></li>
			<li class="sw-admin-heading"><?php echo _('System'); ?></li>
			<li><a href="#editsettingstab-8"><span><?php echo _('Activity'); ?></span></a></li>
			<li><a href="#editsettingstab-7"><span><?php echo _('API &amp; RSS'); ?></span></a></li>
			<li><a href="#editsettingstab-4"><span><?php echo _('Performance &amp; cache'); ?></span></a></li>
<?php } ?>
		</ul>
		<div class="sw-admin-links">
<?php if ($tplHelper->allowed(SpotSecurity::spotsec_list_all_users, '') || $tplHelper->allowed(SpotSecurity::spotsec_edit_securitygroups, '')) { ?>
			<a href="?page=render&amp;tplname=usermanagement"><?php echo _('User &amp; group management'); ?></a>
<?php } ?>
<?php if ($tplHelper->allowed(SpotSecurity::spotsec_view_statics, '')) { ?>
			<a href="?page=statistics"><?php echo _('Statistics'); ?></a>
<?php } ?>
		</div>

<?php if ($tplHelper->allowed(SpotSecurity::spotsec_edit_settings, '')) { ?>		
		<div id="editsettingstab-1" class="ui-tabs-hide newsservers">
			<fieldset>
				<dl>
					<dt><label for="editsettingsform[deny_robots]"><?php echo _('Try to prevent robots from indexing this installation'); ?></label></dt>
					<dd><input type="checkbox" name="editsettingsform[deny_robots]" <?php if ($this->_settings->get('deny_robots')) {
    echo 'checked="checked"';
} ?>></dd>

					<dt><label for="editsettingsform[systemfrommail]"><?php echo _('Sender email address'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[systemfrommail]" value="<?php echo htmlspecialchars($this->_settings->get('systemfrommail'), ENT_QUOTES); ?>"></dd>

					<dt><label for="editsettingsform[sendwelcomemail]"><?php echo _('Always send welcome e-mail to new users'); ?></label></dt>
					<dd><input type="checkbox" name="editsettingsform[sendwelcomemail]" <?php if ($this->_settings->get('sendwelcomemail')) {
    echo 'checked="checked"';
} ?>></dd>

					<dt><label for="use_smtp"><?php echo _('Use SMTP (keep disabled to use PHP\'s internal mail function)'); ?></label></dt>
					<dd><input type="checkbox" class="enabler" name="editsettingsform[smtp][use][switch]" id="use_smtp" <?php if (isset($smtp['use']) && $smtp['use']) {
    echo 'checked="checked"';
} ?>>
					<fieldset id="content_use_smtp" class="serverSettings">
						<dt><label for="editsettingsform[smtp][host]"><?php echo _('Hostname'); ?></label></dt>
						<dd><input type="text" name="editsettingsform[smtp][host]" value="<?php echo htmlspecialchars($smtp['host'], ENT_QUOTES); ?>"></dd>

						<dt><label for="editsettingsform[smtp][user]"><?php echo _('Username'); ?></label></dt>
						<dd><input type="text" name="editsettingsform[smtp][user]" value="<?php echo htmlspecialchars($smtp['user'], ENT_QUOTES); ?>"></dd>

						<dt><label for="editsettingsform[smtp][pass]"><?php echo _('Password'); ?></label></dt>
						<dd><input type="password" name="editsettingsform[smtp][pass]" value="<?php echo htmlspecialchars($smtp['pass'], ENT_QUOTES); ?>"></dd>

						<dt><label for="editsettingsform[smtp][port]"><?php echo _('Port'); ?></label></dt>
						<dd><input type="text" name="editsettingsform[smtp][port]" value="<?php echo htmlspecialchars($smtp['port'], ENT_QUOTES); ?>"></dd>
					</fieldset>

					<dt><label for="editsettingsform[cookie_expires]"><?php echo _('Cookie expires after (in days)'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[cookie_expires]" value="<?php echo htmlspecialchars($this->_settings->get('cookie_expires'), ENT_QUOTES); ?>"></dd>

					<!-- Add some explanation about the MS translator API -->
					<p>
						<?php echo _('Spotweb can use the Microsoft Translator API to translate comments and Spot description to the users native language. This requires either a subscription key from Microsoft Cognitive Services in the Azure Portal. You can find instructions at <a href="http://docs.microsofttranslator.com/text-translate.html">http://docs.microsofttranslator.com/text-translate.html</a>. Please enter the subscription key in the field below.'); ?>

					</p>
					<dt><label for="editsettingsform[ms_translator_subscriptionkey]"><?php echo _('Microsoft Cognitive Services - Subscription Key'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[ms_translator_subscriptionkey]" value="<?php echo htmlspecialchars($this->_settings->get('ms_translator_subscriptionkey'), ENT_QUOTES); ?>"></dd>

				</dl>

			</fieldset>
		</div>

		<div id="editsettingstab-2" class="ui-tabs-hide newsservers">
			<fieldset>
				<dt><label for="use_nntp_hdr"><?php echo _('Default newsserver'); ?></label></dt>
			</fieldset>
			<fieldset class="serverSettings">
				<dl>
					<dt><label for="editsettingsform[nntp_nzb][host]"><?php echo _('Hostname'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[nntp_nzb][host]" value="<?php echo htmlspecialchars($nntp_nzb['host'], ENT_QUOTES); ?>"></dd>

					<dt><label for="editsettingsform[nntp_nzb][user]"><?php echo _('Username'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[nntp_nzb][user]" value="<?php echo htmlspecialchars($nntp_nzb['user'], ENT_QUOTES); ?>"></dd>

					<dt><label for="editsettingsform[nntp_nzb][pass]"><?php echo _('Password'); ?></label></dt>
					<dd><input type="password" name="editsettingsform[nntp_nzb][pass]" value="<?php echo htmlspecialchars($nntp_nzb['pass'], ENT_QUOTES); ?>"></dd>

					<dt><label for="use_encryption_nzb"><?php echo _('Encryption (recommended; disabling sends credentials and articles unencrypted)'); ?></label></dt>
					<dd><input type="checkbox" class="enabler" name="editsettingsform[nntp_nzb][enc][switch]" id="use_encryption_nzb" <?php if ($nntp_nzb['enc']) {
    echo 'checked="checked"';
} ?>>
					<fieldset id="content_use_encryption_nzb">
						<select name="editsettingsform[nntp_nzb][enc][select]">
							<option <?php if (Services_Nntp_Encryption::normalize($nntp_nzb['enc']) === Services_Nntp_Encryption::ImplicitTls) {
    echo 'selected="selected"';
} ?> value="tls"><?php echo _('TLS (implicit encryption, usually port 563)'); ?></option>
							<option <?php if (Services_Nntp_Encryption::normalize($nntp_nzb['enc']) === Services_Nntp_Encryption::StartTls) {
    echo 'selected="selected"';
} ?> value="starttls"><?php echo _('STARTTLS (explicit upgrade, usually port 119)'); ?></option>
						</select>					
					<label for="editsettingsform[nntp_nzb][verifyname]"><?php echo '&nbsp;&nbsp;&nbsp'; echo _('Verify certificate and hostname (recommended)'); ?></label>
                        <input type="checkbox" name="editsettingsform[nntp_nzb][verifyname][switch]" style="vertical-align:bottom" id="use_verifyname_nzb" <?php if ($nntp_nzb['verifyname']) {
    echo 'checked="checked"';
} ?>>
					</fieldset>
                    </dd>
					<dt><label for="editsettingsform[nntp_nzb][port]"><?php echo _('Port'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[nntp_nzb][port]" value="<?php echo htmlspecialchars($nntp_nzb['port'], ENT_QUOTES); ?>"></dd>

					<dt><label for="editsettingsform[nntp_nzb][article_pipeline_depth]"><?php echo _('Article pipeline depth'); ?><br /><?php echo _('Used by scheduled bulk retrieval. Use 1 to disable pipelining. Valid range: 1-128.'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[nntp_nzb][article_pipeline_depth]" value="<?php echo htmlspecialchars($nntp_nzb['article_pipeline_depth'], ENT_QUOTES); ?>"></dd>

					<dt><label for="editsettingsform[nntp_nzb][buggy]"><?php echo _('Buggy (Some newsservers lose messages once in a while)'); ?></label></dt>
					<dd><input type="checkbox" name="editsettingsform[nntp_nzb][buggy]" <?php if ($nntp_nzb['buggy']) {
    echo 'checked="checked"';
} ?>></dd>
				</dl>
			</fieldset>

			<fieldset>
				<dt><label for="use_nntp_hdr"><?php echo _('Use different server for headers?'); ?></label></dt>
				<dd><input type="checkbox" class="enabler" name="editsettingsform[nntp_hdr][use]" id="use_nntp_hdr" <?php if (!isset($nntp_hdr['isadummy'])) {
    echo 'checked="checked"';
} ?>></dd>
			</fieldset>
			<fieldset id="content_use_nntp_hdr" class="serverSettings">
				<dl>
					<dt><label for="editsettingsform[nntp_hdr][host]"><?php echo _('Hostname'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[nntp_hdr][host]" value="<?php echo htmlspecialchars($nntp_hdr['host'], ENT_QUOTES); ?>"></dd>

					<dt><label for="editsettingsform[nntp_hdr][user]"><?php echo _('Username'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[nntp_hdr][user]" value="<?php echo htmlspecialchars($nntp_hdr['user'], ENT_QUOTES); ?>"></dd>

					<dt><label for="editsettingsform[nntp_hdr][pass]"><?php echo _('Password'); ?></label></dt>
					<dd><input type="password" name="editsettingsform[nntp_hdr][pass]" value="<?php echo htmlspecialchars($nntp_hdr['pass'], ENT_QUOTES); ?>"></dd>

					<dt><label for="use_encryption_hdr"><?php echo _('Encryption (recommended; disabling sends credentials and articles unencrypted)'); ?></label></dt>
					<dd><input type="checkbox" class="enabler" name="editsettingsform[nntp_hdr][enc][switch]" id="use_encryption_hdr" <?php if ($nntp_hdr['enc']) {
    echo 'checked="checked"';
} ?>>
					<fieldset id="content_use_encryption_hdr">
						<select name="editsettingsform[nntp_hdr][enc][select]">
							<option <?php if (Services_Nntp_Encryption::normalize($nntp_hdr['enc']) === Services_Nntp_Encryption::ImplicitTls) {
    echo 'selected="selected"';
} ?> value="tls"><?php echo _('TLS (implicit encryption, usually port 563)'); ?></option>
							<option <?php if (Services_Nntp_Encryption::normalize($nntp_hdr['enc']) === Services_Nntp_Encryption::StartTls) {
    echo 'selected="selected"';
} ?> value="starttls"><?php echo _('STARTTLS (explicit upgrade, usually port 119)'); ?></option>
						</select>					
					<label for="editsettingsform[nntp_hdr][verifyname]"><?php echo '&nbsp;&nbsp;&nbsp'; echo _('Verify certificate and hostname (recommended)'); ?></label>
                        <input type="checkbox" name="editsettingsform[nntp_hdr][verifyname][switch]" style="vertical-align:bottom" id="use_verifyname_hdr" <?php if ($nntp_hdr['verifyname']) {
    echo 'checked="checked"';
} ?>>
                    </fieldset>
                    </dd>

					<dt><label for="editsettingsform[nntp_hdr][port]"><?php echo _('Port'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[nntp_hdr][port]" value="<?php echo htmlspecialchars($nntp_hdr['port'], ENT_QUOTES); ?>"></dd>

					<dt><label for="editsettingsform[nntp_hdr][article_pipeline_depth]"><?php echo _('Article pipeline depth'); ?><br /><?php echo _('Used by scheduled bulk retrieval. Use 1 to disable pipelining. Valid range: 1-128.'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[nntp_hdr][article_pipeline_depth]" value="<?php echo htmlspecialchars($nntp_hdr['article_pipeline_depth'], ENT_QUOTES); ?>"></dd>

					<dt><label for="editsettingsform[nntp_hdr][buggy]"><?php echo _('Buggy (Some newsservers lose messages once in a while)'); ?></label></dt>
					<dd><input type="checkbox" name="editsettingsform[nntp_hdr][buggy]" <?php if ($nntp_hdr['buggy']) {
    echo 'checked="checked"';
} ?>></dd>
				</dl>
			</fieldset>

			<fieldset>
				<dt><label for="use_nntp_post"><?php echo _('Use different server for posting?'); ?></label></dt>
				<dd><input type="checkbox" class="enabler" name="editsettingsform[nntp_post][use]" id="use_nntp_post" <?php if (!isset($nntp_post['isadummy'])) {
    echo 'checked="checked"';
} ?>></dd>
			</fieldset>
			<fieldset id="content_use_nntp_post" class="serverSettings">
				<dl>
					<dt><label for="editsettingsform[nntp_post][host]"><?php echo _('Hostname'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[nntp_post][host]" value="<?php echo htmlspecialchars($nntp_post['host'], ENT_QUOTES); ?>"></dd>

					<dt><label for="editsettingsform[nntp_post][user]"><?php echo _('Username'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[nntp_post][user]" value="<?php echo htmlspecialchars($nntp_post['user'], ENT_QUOTES); ?>"></dd>

					<dt><label for="editsettingsform[nntp_post][pass]"><?php echo _('Password'); ?></label></dt>
					<dd><input type="password" name="editsettingsform[nntp_post][pass]" value="<?php echo htmlspecialchars($nntp_post['pass'], ENT_QUOTES); ?>"></dd>

					<dt><label for="use_encryption_post"><?php echo _('Encryption (recommended; disabling sends credentials and articles unencrypted)'); ?></label></dt>
					<dd><input type="checkbox" class="enabler" name="editsettingsform[nntp_post][enc][switch]" id="use_encryption_post" <?php if ($nntp_post['enc']) {
    echo 'checked="checked"';
} ?>>
					<fieldset id="content_use_encryption_post">
						<select name="editsettingsform[nntp_post][enc][select]">
							<option <?php if (Services_Nntp_Encryption::normalize($nntp_post['enc']) === Services_Nntp_Encryption::ImplicitTls) {
    echo 'selected="selected"';
} ?> value="tls"><?php echo _('TLS (implicit encryption, usually port 563)'); ?></option>
							<option <?php if (Services_Nntp_Encryption::normalize($nntp_post['enc']) === Services_Nntp_Encryption::StartTls) {
    echo 'selected="selected"';
} ?> value="starttls"><?php echo _('STARTTLS (explicit upgrade, usually port 119)'); ?></option>
						</select>					
					<label for="editsettingsform[nntp_post][verifyname]"><?php echo '&nbsp;&nbsp;&nbsp'; echo _('Verify certificate and hostname (recommended)'); ?></label>
                        <input type="checkbox" name="editsettingsform[nntp_post][verifyname][switch]" style="vertical-align:bottom" id="use_verifyname_post" <?php if ($nntp_post['verifyname']) {
    echo 'checked="checked"';
} ?>>
					</fieldset>
                     </dd>
					<dt><label for="editsettingsform[nntp_post][port]"><?php echo _('Port'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[nntp_post][port]" value="<?php echo htmlspecialchars($nntp_post['port'], ENT_QUOTES); ?>"></dd>

					<dt><label for="editsettingsform[nntp_post][article_pipeline_depth]"><?php echo _('Article pipeline depth'); ?><br /><?php echo _('Reserved for future scheduled retrieval paths. Use 1 to disable pipelining. Valid range: 1-128.'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[nntp_post][article_pipeline_depth]" value="<?php echo htmlspecialchars($nntp_post['article_pipeline_depth'], ENT_QUOTES); ?>"></dd>

					<input type="hidden" name="editsettingsform[nntp_post][buggy]" value="">
				</dl>
			</fieldset>
			</div>

		<div id="editsettingstab-3" class="ui-tabs-hide">
			<fieldset>
				<dl>
					<dt><label for="editsettingsform[retention]"><?php echo _('Retention on spots (in days). Older spots will be erased. Select 0 to keep all spots.'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[retention]" value="<?php echo htmlspecialchars($this->_settings->get('retention'), ENT_QUOTES); ?>"></dd>

					<dt><label for="editsettingsform[retentiontype]"><?php echo _('Retention is for either everything or only the cached data'); ?></label></dt>
						<select name="editsettingsform[retentiontype]">
							<option <?php if ($this->_settings->get('retentiontype') == 'everything') {
    echo 'selected="selected"';
} ?> value="everything"><?php echo _('Remove everything'); ?></option>
							<option <?php if ($this->_settings->get('retentiontype') == 'fullonly') {
    echo 'selected="selected"';
} ?> value="fullonly"><?php echo _('Only extra data but keep spots'); ?></option>
						</select>					

					<dt><label for="editsettingsform[retrieve_newer_than]"><?php echo _('Retrieve spots after... Select November 1, 2009 to fetch all spots'); ?><br /><?php echo _('To skip all FTD spots select November 24, 2010'); ?></label></dt>
					<dd><input type="text" id="retrieve_newer_than" name="editsettingsform[retrieve_newer_than]"><div id="datepicker"></div></dd>

					<dt><label for="editsettingsform[retrieve_full]"><?php echo _('Retrieve full spots'); ?></label></dt>
					<dd><input type="checkbox" class="enabler" name="editsettingsform[retrieve_full]" id="use_retrieve_full" <?php if ($this->_settings->get('retrieve_full')) {
    echo 'checked="checked"';
} ?>></dd>
					<fieldset id="content_use_retrieve_full">
						<dt><label for="editsettingsform[prefetch_image]"><?php echo _('Prefetch images'); ?></label></dt>
						<dd><input type="checkbox" name="editsettingsform[prefetch_image]" <?php if ($this->_settings->get('prefetch_image')) {
    echo 'checked="checked"';
} ?>></dd>

						<dt><label for="editsettingsform[prefetch_nzb]"><?php echo _('Prefetch NZB files'); ?></label></dt>
						<dd><input type="checkbox" name="editsettingsform[prefetch_nzb]" <?php if ($this->_settings->get('prefetch_nzb')) {
    echo 'checked="checked"';
} ?>></dd>
					</fieldset>

					<dt><label for="editsettingsform[retrieve_comments]"><?php echo _('Retrieve comments'); ?></label></dt>
					<dd><input type="checkbox" class="enabler" name="editsettingsform[retrieve_comments]" id="use_retrieve_comments" <?php if ($this->_settings->get('retrieve_comments')) {
    echo 'checked="checked"';
} ?>></dd>
					<fieldset id="content_use_retrieve_comments">
						<dt><label for="editsettingsform[retrieve_full_comments]"><?php echo _('Retrieve full comments'); ?></label></dt>
						<dd><input type="checkbox" name="editsettingsform[retrieve_full_comments]" <?php if ($this->_settings->get('retrieve_full_comments')) {
    echo 'checked="checked"';
} ?>></dd>
					</fieldset>

					<dt><label for="editsettingsform[retrieve_reports]"><?php echo _('Retrieve reports'); ?></label></dt>
					<dd><input type="checkbox" name="editsettingsform[retrieve_reports]" <?php if ($this->_settings->get('retrieve_reports')) {
    echo 'checked="checked"';
} ?>></dd>
				</dl>
			</fieldset>
		</div>

		<div id="editsettingstab-4" class="ui-tabs-hide">
			<fieldset>
				<dl>
					<dt><label for="editsettingsform[enable_timing]"><?php echo _('Enable timing'); ?><br /><?php echo _('Use this only to identify speed problems within Spotweb.'); ?> <?php echo _('Not suitable for public installations'); ?></label></dt>
					<dd><input type="checkbox" name="editsettingsform[enable_timing]" <?php if ($this->_settings->get('enable_timing')) {
    echo 'checked="checked"';
} ?>></dd>

					<dt><label for="editsettingsform[enable_stacktrace]"><?php echo _('Enable stacktrace'); ?><br /><?php echo _('Stacktraces make identifying problems easy, but may contain sensitive information.'); ?> <?php echo _('Not suitable for public installations'); ?></label></dt>
					<dd><input type="checkbox" name="editsettingsform[enable_stacktrace]" <?php if ($this->_settings->get('enable_stacktrace')) {
    echo 'checked="checked"';
} ?>></dd>

					<dt><label for="editsettingsform[retrieve_increment]"><?php echo _('Retrieve increment'); ?><br /><?php echo _('Lower this if you get timeouts during retrieve'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[retrieve_increment]" value="<?php echo htmlspecialchars($this->_settings->get('retrieve_increment'), ENT_QUOTES); ?>"></dd>

					<dt><label for="editsettingsform[spot_moderation]"><?php echo _('Handling of moderation messages'); ?></label></dt>
					<dd><select name="editsettingsform[spot_moderation]">
						<option <?php if ($this->_settings->get('spot_moderation') == 'disable') {
    echo 'selected="selected"';
} ?> value="disable"><?php echo _('Do nothing'); ?></option>
						<option <?php if ($this->_settings->get('spot_moderation') == 'act') {
    echo 'selected="selected"';
} ?> value="act"><?php echo _('Delete moderated spots'); ?></option>
						<option <?php if ($this->_settings->get('spot_moderation') == 'markspot') {
    echo 'selected="selected"';
} ?> value="markspot"><?php echo _('Mark moderated spots as moderated'); ?></option>
					</select></dd>
					
					<dt><label for="editsettingsform[imageover_subcats]"><?php echo _('Enable imagepreview in spot overview'); ?></label></dt>
					<dd><input type="checkbox" name="editsettingsform[imageover_subcats]" <?php if ($this->_settings->get('imageover_subcats')) {
    echo 'checked="checked"';
} ?>></dd>

					<dt><label for="editsettingsform[highlight]"><?php echo _('Highlight spots based on amount of comments'); ?></label></dt>
					<dd><input type="checkbox" name="editsettingsform[highlight]" <?php if ($this->_settings->get('highlight')) {
    echo 'checked="checked"';
} ?>></dd>
					
					<dt><label for="editsettingsform[highcount]"><?php echo _('Amount of comments to highlight spot'); ?></label></dt>
					<dd><input type="text" name="editsettingsform[highcount]" value="<?php echo htmlspecialchars($this->_settings->get('highcount'), ENT_QUOTES); ?>"></dd>
					
					<dt><label for="editsettingsform[prepare_statistics]"><?php echo _('Prepare statistics during retrieve'); ?></label></dt>
					<dd><input type="checkbox" name="editsettingsform[prepare_statistics]" <?php if ($this->_settings->get('prepare_statistics')) {
    echo 'checked="checked"';
} ?>></dd>

					<dt><label for="editsettingsform[external_blacklist]"><?php echo _('Fetch the external blacklist during retrieve'); ?></label></dt>
					<dd><input type="checkbox" name="editsettingsform[external_blacklist]" <?php if ($this->_settings->get('external_blacklist')) {
    echo 'checked="checked"';
} ?>></dd>
					<dt><label for="editsettingsform[blacklist_url]"><?php echo _('Blacklist used during retrieve:'); ?></label></dt>
					<dd><input style="width: 300px;" type="text" name="editsettingsform[blacklist_url]" value="<?php echo htmlspecialchars($this->_settings->get('blacklist_url'), ENT_QUOTES); ?>"></dd>
					
					<dt><label for="editsettingsform[external_whitelist]"><?php echo _('Fetch the external whitelist during retrieve'); ?></label></dt>
					<dd><input type="checkbox" name="editsettingsform[external_whitelist]" <?php if ($this->_settings->get('external_whitelist')) {
    echo 'checked="checked"';
} ?>></dd>
					<dt><label for="editsettingsform[whitelist_url]"><?php echo _('Whitelist used during retrieve:'); ?></label></dt>
					<dd><input style="width: 300px;" type="text" name="editsettingsform[whitelist_url]" value="<?php echo htmlspecialchars($this->_settings->get('whitelist_url'), ENT_QUOTES); ?>"></dd>
				</dl>
			</fieldset>
		</div>

<!-- Users & registration -->
		<div id="editsettingstab-6" class="ui-tabs-hide">
			<fieldset>
				<h3 class="sw-admin-section"><?php echo _('Who can create an account?'); ?></h3>
				<div class="sw-choice-grid">
					<label class="sw-choice">
						<input type="radio" name="editsettingsform[registration][mode]" value="admins" <?php if ($registrationmode === 'admins') { echo 'checked="checked"'; } ?>>
						<span><strong><?php echo _('Admins only'); ?></strong><span><?php echo _('Only admins and trusted users create accounts. Visitors cannot sign up.'); ?></span></span>
					</label>
					<label class="sw-choice">
						<input type="radio" name="editsettingsform[registration][mode]" value="open" <?php if ($registrationmode === 'open') { echo 'checked="checked"'; } ?>>
						<span><strong><?php echo _('Open registration'); ?></strong><span><?php echo _('Visitors can create an account themselves. Their password is sent by e-mail, so make sure mail works.'); ?></span></span>
					</label>
				</div>

				<h3 class="sw-admin-section"><?php echo _('New users are added to these groups'); ?></h3>
				<div class="sw-check-list">
<?php foreach ($newusergroups as $group) { ?>
					<label><input type="checkbox" name="editsettingsform[registration][groups][]" value="<?php echo (int) $group['id']; ?>" <?php if ($group['selected']) { echo 'checked="checked"'; } ?>> <?php echo htmlspecialchars($group['name'], ENT_QUOTES); ?></label>
<?php } ?>
				</div>
				<p class="sw-hint"><?php echo _('Group permissions are managed under User &amp; group management.'); ?></p>
			</fieldset>
		</div>

<!-- Activity -->
		<div id="editsettingstab-8" class="ui-tabs-hide sw-activity" data-sw-nosave="1">
<?php if ($activity === null) { ?>
			<p class="sw-hint"><?php echo _('Run bin/upgrade-db.php to start collecting activity figures.'); ?></p>
<?php } else {
    $today = end($activity['daily']);
    $maxDay = 1;
    foreach ($activity['daily'] as $d) { $maxDay = max($maxDay, $d['total']); }
    $thousandsSep = (strpos((string) ($currentSession['user']['prefs']['user_language'] ?? 'en_US'), 'en') === 0) ? ',' : '.';
    $fmt = function ($n) use ($thousandsSep) { return number_format((int) $n, 0, '', $thousandsSep); };
    $spots = $activity['spots'];
?>
			<h3 class="sw-admin-section"><?php echo _('Today'); ?></h3>
			<div class="sw-kpis">
				<div class="sw-kpi"><span><?php echo _('Requests'); ?></span><strong><?php echo $fmt($today['total']); ?></strong></div>
				<div class="sw-kpi"><span><?php echo _('Via the API'); ?></span><strong><?php echo $fmt($today['api']); ?></strong></div>
				<div class="sw-kpi"><span><?php echo _('Via the web interface'); ?></span><strong><?php echo $fmt($today['web']); ?></strong></div>
				<div class="sw-kpi"><span><?php echo _('Via RSS'); ?></span><strong><?php echo $fmt($today['rss']); ?></strong></div>
			</div>

			<div class="sw-panel">
				<div class="sw-panel-head">
					<h3 class="sw-admin-section"><?php echo _('Requests per day'); ?></h3>
					<div class="sw-legend"><span><i class="sw-c-api"></i><?php echo _('API'); ?></span><span><i class="sw-c-web"></i><?php echo _('Web'); ?></span><span><i class="sw-c-rss"></i><?php echo _('RSS'); ?></span></div>
				</div>
				<div class="sw-bars" role="img" aria-label="<?php echo _('Stacked bar chart of requests per day for the last 14 days'); ?>">
<?php foreach ($activity['daily'] as $d) { ?>
					<div class="sw-bar" title="<?php echo date('D j M', $d['stamp']).': '.$fmt($d['api']).' API, '.$fmt($d['web']).' web, '.$fmt($d['rss']).' RSS'; ?>">
						<span class="sw-bar-total"><?php echo $fmt($d['total']); ?></span>
						<div class="sw-bar-stack">
							<i class="sw-c-rss" style="height: <?php echo round(100 * $d['rss'] / $maxDay, 2); ?>%"></i>
							<i class="sw-c-web" style="height: <?php echo round(100 * $d['web'] / $maxDay, 2); ?>%"></i>
							<i class="sw-c-api" style="height: <?php echo round(100 * $d['api'] / $maxDay, 2); ?>%"></i>
						</div>
						<span class="sw-bar-label"><?php echo date('j/n', $d['stamp']); ?></span>
					</div>
<?php } ?>
				</div>
			</div>

			<div class="sw-panel">
				<h3 class="sw-admin-section"><?php echo _('Most active users (30 days)'); ?></h3>
				<div class="sw-table-wrap">
				<table class="sw-table">
					<thead><tr><th><?php echo _('User'); ?></th><th class="num"><?php echo _('API'); ?></th><th class="num"><?php echo _('Web'); ?></th><th class="num"><?php echo _('RSS'); ?></th><th class="num"><?php echo _('Total'); ?></th><th><?php echo _('Mostly via'); ?></th></tr></thead>
					<tbody>
<?php foreach ($activity['topusers'] as $u) {
        $main = 'api'; foreach (['web', 'rss'] as $c) { if ($u[$c] > $u[$main]) { $main = $c; } }
        $mainLabel = ['api' => _('API'), 'web' => _('Web'), 'rss' => _('RSS')][$main]; ?>
						<tr>
							<td><?php echo htmlspecialchars($u['username'] !== '' ? $u['username'] : '#'.$u['userid'], ENT_QUOTES); ?></td>
							<td class="num"><?php echo $fmt($u['api']); ?></td>
							<td class="num"><?php echo $fmt($u['web']); ?></td>
							<td class="num"><?php echo $fmt($u['rss']); ?></td>
							<td class="num"><strong><?php echo $fmt($u['total']); ?></strong></td>
							<td><span class="sw-pill sw-pill-<?php echo $main; ?>"><?php echo $mainLabel; ?></span></td>
						</tr>
<?php } ?>
<?php if (empty($activity['topusers'])) { ?>
						<tr><td colspan="6" class="sw-hint"><?php echo _('No requests counted yet.'); ?></td></tr>
<?php } ?>
					</tbody>
				</table>
				</div>
			</div>

			<div class="sw-panel-grid">
				<div class="sw-panel">
					<h3 class="sw-admin-section"><?php echo _('NZB downloads (7 days)'); ?></h3>
					<p class="sw-big"><?php echo $fmt($activity['downloads']['total']); ?></p>
<?php foreach (array_slice($activity['downloads']['clients'], 0, 6, true) as $client => $n) { ?>
					<div class="sw-row"><span><?php echo htmlspecialchars($client === '' || $client === '?' ? _('Web interface') : $client, ENT_QUOTES); ?></span><span class="num"><?php echo $fmt($n); ?></span></div>
<?php } ?>
				</div>
				<div class="sw-panel">
					<h3 class="sw-admin-section"><?php echo _('Spots in the database'); ?></h3>
					<p class="sw-big"><?php echo $fmt($spots['count']); ?></p>
					<div class="sw-row"><span><?php echo _('Oldest spot'); ?></span><span><?php echo $spots['oldest'] ? date('j M Y', $spots['oldest']) : '–'; ?></span></div>
					<div class="sw-row"><span><?php echo _('Newest spot'); ?></span><span><?php echo $spots['newest'] ? date('j M Y, H:i', $spots['newest']) : '–'; ?></span></div>
					<div class="sw-row"><span><?php echo _('Retrieve spots newer than'); ?></span><span><?php echo date('j M Y', max(1230789600, (int) $this->_settings->get('retrieve_newer_than'))); ?></span></div>
				</div>
			</div>
<?php } ?>
		</div>

<!-- API & RSS -->
		<div id="editsettingstab-7" class="ui-tabs-hide">
			<fieldset>
				<h3 class="sw-admin-section"><?php echo _('Newznab API'); ?></h3>
				<div class="sw-check-list sw-check-list--stack">
					<label><input type="checkbox" name="editsettingsform[api_enabled]" <?php if ($this->_settings->isEnabled('api_enabled')) { echo 'checked="checked"'; } ?>> <?php echo _('Enable the API for Prowlarr, Sonarr, Radarr and other Newznab clients'); ?></label>
				</div>
				<p class="sw-hint"><?php echo _('Indexer URL for these apps:'); ?> <code class="sw-code"><?php echo htmlspecialchars($tplHelper->makeBaseUrl('full'), ENT_QUOTES); ?></code> &middot; <?php echo _('API path:'); ?> <code class="sw-code">/api</code></p>

				<h3 class="sw-admin-section"><?php echo _('Limits per user'); ?></h3>
				<p class="sw-hint"><?php echo _('Protects the server against apps that ask too much. 0 means no limit. Apps get a "limit reached" answer with the time to wait, which Prowlarr, Sonarr and Radarr respect.'); ?></p>
				<div class="sw-limit-grid">
					<label><span><?php echo _('API requests per minute'); ?></span><input type="number" min="0" step="1" name="editsettingsform[api_limit_minute]" value="<?php echo (int) $this->_settings->get('api_limit_minute'); ?>"></label>
					<label><span><?php echo _('API requests per day'); ?></span><input type="number" min="0" step="1" name="editsettingsform[api_limit_day]" value="<?php echo (int) $this->_settings->get('api_limit_day'); ?>"></label>
					<label><span><?php echo _('NZB downloads via the API per day'); ?></span><input type="number" min="0" step="1" name="editsettingsform[api_download_limit]" value="<?php echo (int) $this->_settings->get('api_download_limit'); ?>"></label>
				</div>

				<h3 class="sw-admin-section"><?php echo _('RSS feed'); ?></h3>
				<div class="sw-check-list sw-check-list--stack">
					<label><input type="checkbox" name="editsettingsform[rss_enabled]" <?php if ($this->_settings->isEnabled('rss_enabled')) { echo 'checked="checked"'; } ?>> <?php echo _('Enable the RSS feed, with a download link to the NZB in every item'); ?></label>
				</div>
				<p class="sw-hint"><?php echo _('Feed address (every user has their own API key, shown in their account):'); ?><br><code class="sw-code"><?php echo htmlspecialchars($tplHelper->makeBaseUrl('full'), ENT_QUOTES); ?>?page=rss&amp;apikey=<?php echo _('YOUR-API-KEY'); ?></code></p>
				<p class="sw-hint"><?php echo _('Add search terms or filters the same way as in the address bar of the spot list, for example &amp;search[tree]=cat0_z0 for movies.'); ?></p>
				<p class="sw-hint"><?php echo _('A user also needs the RSS and API permissions in their group (User &amp; group management).'); ?></p>
			</fieldset>
		</div>

<!-- Custom Stylesheet -->
		<div id="editsettingstab-5" class="ui-tabs-hide">
			<fieldset>
				<h3 class="sw-admin-section"><?php echo _('Site theme'); ?></h3>
				<p class="sw-hint"><?php echo _('The colours every visitor sees. Visitors can still pick another theme for themselves in the toolbar.'); ?></p>
				<div class="sw-theme-grid">
<?php $siteTheme = Services_Admin_Themes::getSiteTheme($this->_settings);
foreach (Services_Admin_Themes::getThemes() as $themeId => $theme) { ?>
					<label class="sw-choice sw-theme-choice">
						<input type="radio" name="editsettingsform[site_theme]" value="<?php echo $themeId; ?>" <?php if ($siteTheme === $themeId) { echo 'checked="checked"'; } ?>>
						<span>
							<span class="sw-theme-swatch" aria-hidden="true" style="background: <?php echo $theme['swatch'][0]; ?>;"><i style="background: <?php echo $theme['swatch'][1]; ?>;"></i><b style="background: <?php echo $theme['swatch'][2]; ?>;"></b></span>
							<strong><?php echo htmlspecialchars($theme['name'], ENT_QUOTES); ?></strong>
							<span><?php echo htmlspecialchars($theme['description'], ENT_QUOTES); ?></span>
						</span>
					</label>
<?php } ?>
				</div>

				<h3 class="sw-admin-section"><?php echo _('Custom CSS'); ?></h3>
				<dt>
					<label for="editsettingsform[customcss]"><?php echo _('Use custom CSS?'); ?></label>
				</dt>
				<dd>
					<textarea name="editsettingsform[customcss]" rows="15" cols="120"><?php echo htmlspecialchars($this->_settings->get('customcss')); ?></textarea>
				</dd>
			</fieldset>
		</div>
<!-- Einde Custom Stylesheet -->

<?php } ?>

		<div class="editSettingsButtons">
			<input class="greyButton" type="submit" name="editsettingsform[submitedit]" value="<?php echo _('Save changes'); ?>">
			<input class="greyButton" type="submit" name="editsettingsform[submitcancel]" value="<?php echo _('Cancel'); ?>">
			<div class="clear"></div>
		</div>
	</div>
</form>
<?php
    $toRunJsCode = 'initializeSettingsPage();';
    require_once __DIR__.'/includes/footer.inc.php';
