<?php
/*
 * "About Spotweb" dialog from the user menu
 */
$inst = (new Services_Updater_Installed())->info();
$isAdmin = $tplHelper->allowed(SpotSecurity::spotsec_edit_settings, '');
$check = null;
if ($isAdmin) {
    try {
        $check = (new Services_Updater_Check($this->_daoFactory->getConnection()))->cached();
    } catch (Exception $x) {
        $check = null;
    } // catch
} // if
?>
<div class="sw-about-dialog">
	<dl>
		<dt><?php echo _('Version'); ?></dt><dd><?php echo htmlspecialchars($inst['version']); ?></dd>
<?php if ($isAdmin) { ?>
		<dt><?php echo _('Build'); ?></dt><dd><?php echo ($inst['commit'] !== '') ? htmlspecialchars($inst['short'].($inst['branch'] !== '' ? ' ('.$inst['branch'].')' : '')) : '–'; ?></dd>
		<dt><?php echo _('PHP'); ?></dt><dd><?php echo htmlspecialchars(PHP_VERSION); ?></dd>
		<dt><?php echo _('Database layout'); ?></dt><dd><?php echo htmlspecialchars($inst['schema']); ?></dd>
<?php } ?>
	</dl>
<?php if ($isAdmin && $check && in_array($check['state'], ['behind', 'unknown'], true) && empty($check['blocked'])) { ?>
	<p class="sw-about-update"><?php echo _('An update is available'); ?></p>
<?php } ?>
<?php if ($isAdmin) { ?>
	<p><a href="<?php echo $tplHelper->makeBaseUrl('path'); ?>?page=editsettings#editsettingstab-11"><?php echo _('Check for updates'); ?> →</a></p>
<?php } ?>
	<p class="sw-hint">Spotweb · <a href="https://github.com/<?php echo htmlspecialchars(Services_Updater_Config::DEFAULT_REPO); ?>" target="_blank" rel="noopener">GitHub</a></p>
</div>
