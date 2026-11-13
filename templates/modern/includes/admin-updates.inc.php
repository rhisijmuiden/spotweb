<?php
/*
 * Admin > About & updates. The tab sits inside the settings form, so its
 * fields have no name and are sent by js/admin-update.js instead.
 */
$upd = $updater ?? null;
$inst = $upd ? $upd['installed'] : (new Services_Updater_Installed())->info();
$cfg = $upd ? $upd['config'] : ['channel' => 'production', 'branch' => 'main', 'method' => 'auto', 'webhook' => '', 'hastoken' => false, 'repo' => Services_Updater_Config::DEFAULT_REPO];
$methods = $upd ? $upd['methods'] : ['git' => '', 'archive' => '', 'auto' => ''];
$base = $tplHelper->makeBaseUrl('path');
$strings = [
    'uptodate'      => _('Spotweb is up to date'),
    'behind'        => _('An update is available'),
    'switch'        => _('Ready to switch channel'),
    'unknown'       => _('The installed build is unknown'),
    'nobranch'      => _('This channel has no branch on GitHub yet'),
    'error'         => _('Could not check for updates'),
    'never'         => _('Not checked yet'),
    'blocked'       => _('This update cannot be installed'),
    'sub_behind'    => _('%d new changes on %s'),
    'sub_switch'    => _('Installs the newest version of %s'),
    'sub_unknown'   => _('Installing the newest version of %s makes it known again'),
    'sub_uptodate'  => _('Newest version of %s'),
    'sub_detected'  => _('Your hosting panel already installed the newest version of %s'),
    'sub_nobranch'  => _('Create the branch %s in the repository to use this channel'),
    'checked'       => _('Last checked: %s'),
    'justnow'       => _('just now'),
    'minutes'       => _('%d min ago'),
    'hours'         => _('%d hours ago'),
    'more'          => _('and %d more'),
    'p_prepare'     => _('Preparing'),
    'p_git'         => _('Fetching with git'),
    'p_download'    => _('Downloading'),
    'p_extract'     => _('Unpacking'),
    'p_plan'        => _('Comparing files'),
    'p_backup'      => _('Backing up the current files'),
    'p_copy'        => _('Installing the new files'),
    'p_rollback'    => _('Putting the previous files back'),
    'p_webhook'     => _('Asking your hosting panel to deploy'),
    'p_database'    => _('Upgrading the database'),
    's_running'     => _('Updating…'),
    's_done'        => _('Update installed'),
    's_failed'      => _('The update failed'),
    's_stalled'     => _('The update stopped responding'),
    's_requested'   => _('Deploy requested'),
    'requested_sub' => _('Your hosting panel installs the update. Check again in a minute.'),
    'done_sub'      => _('Reloading the page…'),
    'confirm'       => _('Install the update now? Spotweb is unavailable for a short while.'),
    'confirm_switch'=> _('Switch this installation to %s now? Spotweb is unavailable for a short while.'),
    'saved'         => _('Saved'),
    'failed'        => _('Something went wrong, try again.'),
    'retrydb'       => _('Run the database upgrade again'),
    'blocked_hint'  => _('Nothing was changed.'),
    'files'         => _('%d files changed, %d added, %d removed'),
];
$channelNames = ['production' => _('Production'), 'test' => _('Test')];
$branchNames = Services_Updater_Config::branches();
?>
		<div id="editsettingstab-11" class="ui-tabs-hide sw-updates" data-sw-nosave="1"
			data-sw-url="<?php echo htmlspecialchars($base.'?page=adminupdate', ENT_QUOTES); ?>"
			data-sw-xsrf="<?php echo htmlspecialchars($tplHelper->generateXsrfCookie('adminupdateform'), ENT_QUOTES); ?>"
			data-sw-repo="<?php echo htmlspecialchars($cfg['repo'], ENT_QUOTES); ?>"
			data-sw-strings="<?php echo htmlspecialchars(json_encode($strings), ENT_QUOTES); ?>"
			data-sw-channels="<?php echo htmlspecialchars(json_encode($channelNames), ENT_QUOTES); ?>"
			data-sw-state="<?php echo htmlspecialchars(json_encode($upd), ENT_QUOTES); ?>">

			<h3 class="sw-admin-section"><?php echo _('About Spotweb'); ?></h3>
			<div class="sw-kpis sw-about-kpis">
				<div class="sw-kpi"><span><?php echo _('Version'); ?></span><strong><?php echo htmlspecialchars($inst['version']); ?></strong></div>
				<div class="sw-kpi"><span><?php echo _('Channel'); ?></span><strong data-sw-upd="channel"><?php echo htmlspecialchars($channelNames[$cfg['channel']] ?? $cfg['channel']); ?></strong><small class="sw-hint" data-sw-upd="branch"><?php echo htmlspecialchars($cfg['branch']); ?></small></div>
				<div class="sw-kpi"><span><?php echo _('Build'); ?></span><strong class="sw-mono"><?php if ($inst['commit'] !== '') { ?><a href="https://github.com/<?php echo htmlspecialchars($cfg['repo'], ENT_QUOTES); ?>/commit/<?php echo $inst['commit']; ?>" target="_blank" rel="noopener"><?php echo $inst['short']; ?></a><?php } else { echo '–'; } ?></strong><small class="sw-hint"><?php echo ($inst['branch'] !== '') ? htmlspecialchars($inst['branch']) : _('branch unknown'); ?></small></div>
				<div class="sw-kpi"><span><?php echo _('PHP'); ?></span><strong><?php echo htmlspecialchars(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION); ?></strong><small class="sw-hint"><?php echo sprintf(_('Database layout %s'), htmlspecialchars($inst['schema'])); ?></small></div>
			</div>

			<h3 class="sw-admin-section"><?php echo _('Updates'); ?></h3>
			<div class="sw-panel sw-update-panel" data-status="idle">
				<div class="sw-retrieve-head">
					<div>
						<strong class="sw-retrieve-title" data-sw-upd="title"><?php echo _('Not checked yet'); ?></strong>
						<span class="sw-hint" data-sw-upd="sub"></span>
					</div>
					<div class="sw-update-actions">
						<button type="button" class="sw-btn-secondary" data-sw-upd-check><?php echo _('Check for updates'); ?></button>
						<button type="button" class="sw-btn-primary" data-sw-upd-start hidden><?php echo _('Update now'); ?></button>
					</div>
				</div>
				<div class="sw-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden><i></i></div>
				<p class="sw-hint sw-update-phase" data-sw-upd="phase" hidden></p>
				<p class="sw-hint sw-retrieve-error" data-sw-upd="error" hidden></p>
				<p class="sw-update-retry" hidden><button type="button" class="sw-btn-secondary" data-sw-upd-retrydb><?php echo _('Run the database upgrade again'); ?></button></p>
				<ol class="sw-changes" data-sw-upd="changes" hidden></ol>
				<p class="sw-hint sw-update-checked" data-sw-upd="checked"></p>
			</div>

			<h3 class="sw-admin-section"><?php echo _('Update channel'); ?></h3>
			<p class="sw-hint"><?php echo _('Choose which version this installation follows. Switching takes effect with the next update.'); ?></p>
			<div class="sw-choice-grid sw-channel-grid">
				<label class="sw-choice">
					<input type="radio" name="sw-update-channel" value="production" <?php if ($cfg['channel'] === 'production') { echo 'checked="checked"'; } ?>>
					<span><strong><?php echo _('Production'); ?> <code class="sw-code"><?php echo htmlspecialchars($branchNames['production']); ?></code></strong><span><?php echo _('Tested versions. Recommended for everyday use.'); ?></span></span>
				</label>
				<label class="sw-choice">
					<input type="radio" name="sw-update-channel" value="test" <?php if ($cfg['channel'] === 'test') { echo 'checked="checked"'; } ?>>
					<span><strong><?php echo _('Test'); ?> <code class="sw-code"><?php echo htmlspecialchars($branchNames['test']); ?></code></strong><span><?php echo _('The newest changes first. Can contain bugs.'); ?></span></span>
				</label>
			</div>

			<details class="sw-panel sw-update-advanced">
				<summary><?php echo _('How updates are installed'); ?></summary>
				<div class="sw-update-form">
					<label for="sw-update-method"><?php echo _('Method'); ?></label>
					<select id="sw-update-method">
						<option value="auto" <?php if ($cfg['method'] === 'auto') { echo 'selected'; } ?>><?php echo _('Automatic'); ?></option>
						<option value="archive" <?php if ($cfg['method'] === 'archive') { echo 'selected'; } ?>><?php echo _('Download from GitHub'); ?></option>
						<option value="git" <?php if ($cfg['method'] === 'git') { echo 'selected'; } ?>><?php echo _('git'); ?></option>
						<option value="webhook" <?php if ($cfg['method'] === 'webhook') { echo 'selected'; } ?>><?php echo _('Hosting panel deploy webhook'); ?></option>
					</select>
					<ul class="sw-method-notes sw-hint">
						<li><strong><?php echo _('Automatic'); ?>:</strong> <?php echo ($methods['auto'] !== '') ? sprintf(_('uses %s on this server'), '<em>'.htmlspecialchars($methods['auto']).'</em>') : _('no method works on this server'); ?></li>
						<li><strong><?php echo _('Download from GitHub'); ?>:</strong> <?php echo ($methods['archive'] === '') ? _('available; replaces the changed files and keeps a backup of the previous ones') : htmlspecialchars($methods['archive']); ?></li>
						<li><strong>git:</strong> <?php echo ($methods['git'] === '') ? _('available') : htmlspecialchars($methods['git']); ?></li>
						<li><strong><?php echo _('Hosting panel deploy webhook'); ?>:</strong> <?php echo _('asks your hosting panel to deploy; the panel must use the same branch'); ?></li>
					</ul>

					<label for="sw-update-webhook"><?php echo _('Deploy webhook address'); ?></label>
					<input type="url" id="sw-update-webhook" placeholder="https://" value="<?php echo htmlspecialchars($cfg['webhook'], ENT_QUOTES); ?>" autocomplete="off">

					<label for="sw-update-token"><?php echo _('GitHub token (optional)'); ?></label>
					<input type="password" id="sw-update-token" placeholder="<?php echo $cfg['hastoken'] ? _('A token is saved; type to replace it') : 'github_pat_…'; ?>" autocomplete="new-password">
					<p class="sw-hint"><?php echo _('Only needed for a private repository or when GitHub limits the number of checks.'); ?><?php if ($cfg['hastoken']) { ?> <label class="sw-inline-check"><input type="checkbox" id="sw-update-cleartoken"> <?php echo _('Remove the saved token'); ?></label><?php } ?></p>

					<div><button type="button" class="sw-btn-secondary" data-sw-upd-save><?php echo _('Save'); ?></button> <span class="sw-hint" data-sw-upd="savemsg"></span></div>
				</div>
			</details>

			<div data-sw-upd="last" hidden>
				<h3 class="sw-admin-section"><?php echo _('Last update'); ?></h3>
				<div class="sw-panel sw-keyvals">
					<div class="sw-row"><span><?php echo _('Started by'); ?></span><span data-sw-upd="last-by"></span></div>
					<div class="sw-row"><span><?php echo _('When'); ?></span><span data-sw-upd="last-when"></span></div>
					<div class="sw-row"><span><?php echo _('From → to'); ?></span><span class="sw-mono" data-sw-upd="last-fromto"></span></div>
					<div class="sw-row"><span><?php echo _('Result'); ?></span><span data-sw-upd="last-result"></span></div>
				</div>
			</div>
		</div>
