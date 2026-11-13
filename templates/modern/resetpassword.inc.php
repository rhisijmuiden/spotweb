<?php
/*
 * Standalone page for "Forgot password?" and "Choose a new password".
 * Rendered by SpotPage_resetpassword for every template.
 */
$e = function ($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
};
$fontBase = 'templates/modern/fonts/';
$titles = [
    'request'  => _('Forgot your password?'),
    'sent'     => _('Check your e-mail'),
    'choose'   => _('Choose a new password'),
    'done'     => _('Password changed'),
    'invalid'  => _('This link no longer works'),
    'disabled' => _('Password reset is turned off'),
];
$title = $titles[$view['mode']];
?><!doctype html>
<html lang="<?php echo $e(substr(SpotTranslation::current(), 0, 2)); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo $e($title); ?> · Spotweb</title>
<link rel="icon" href="?page=statics&amp;type=ico">
<style>
@font-face { font-family: "Inter"; font-weight: 100 900; font-display: swap; src: url(<?php echo $fontBase; ?>inter-latin-wght-normal.woff2) format("woff2-variations"); }
@font-face { font-family: "Space Grotesk"; font-weight: 600; font-display: swap; src: url(<?php echo $fontBase; ?>space-grotesk-latin-600-normal.woff2) format("woff2"); }
:root {
  --bg: #F5F6F9; --surface: #FFFFFF; --surface-2: #F0F2F6; --border: #DDE2EA; --border-strong: #C9D0DB;
  --text: #111827; --text-2: #3F4A5A; --accent: #6D5DF5; --accent-hover: #5B4AE6; --accent-soft: #EEEBFF;
  --success: #0F9F6E; --success-soft: #E3F7EF; --danger: #D93B3B; --danger-soft: #FDE8E8;
  color-scheme: light;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #0E0F14; --surface: #171922; --surface-2: #1F222D; --border: #2A2E3B; --border-strong: #3A3F4F;
    --text: #EDEEF3; --text-2: #B4B9C7; --accent: #8B7CFF; --accent-hover: #9D90FF; --accent-soft: #262145;
    --success: #34D399; --success-soft: #10291F; --danger: #F87171; --danger-soft: #2D1517;
    color-scheme: dark;
  }
}
* { box-sizing: border-box; }
html, body { margin: 0; min-height: 100%; }
body { background: var(--bg); color: var(--text); font: 15px/1.55 "Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 24px 16px; }
.card { width: 100%; max-width: 420px; background: var(--surface); border: 1px solid var(--border); border-radius: 16px;
  padding: 32px 28px; box-shadow: 0 12px 32px rgba(16, 24, 40, .10); }
.brand { display: flex; align-items: center; gap: 10px; font: 600 18px "Space Grotesk", "Inter", sans-serif; margin-bottom: 24px; }
.brand i { width: 12px; height: 12px; border-radius: 50%; background: var(--accent); display: inline-block; }
h1 { font: 600 22px/1.3 "Space Grotesk", "Inter", sans-serif; margin: 0 0 8px; }
p { margin: 0 0 16px; color: var(--text-2); }
label { display: block; font-weight: 600; font-size: 14px; margin: 16px 0 6px; }
input[type=text], input[type=password] { width: 100%; padding: 11px 12px; font: inherit; color: var(--text);
  background: var(--surface-2); border: 1px solid var(--border-strong); border-radius: 8px; }
input:focus { outline: 2px solid var(--accent); outline-offset: 1px; border-color: var(--accent); }
button { margin-top: 22px; width: 100%; padding: 12px 16px; border: 0; border-radius: 8px; cursor: pointer;
  background: var(--accent); color: #fff; font: 600 15px "Inter", sans-serif; }
button:hover { background: var(--accent-hover); }
.note { padding: 12px 14px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; }
.note.ok { background: var(--success-soft); color: var(--text); border: 1px solid var(--success); }
.note.err { background: var(--danger-soft); color: var(--text); border: 1px solid var(--danger); }
.note ul { margin: 0; padding-left: 18px; }
.links { margin-top: 20px; font-size: 14px; text-align: center; }
a { color: var(--accent); font-weight: 600; text-decoration: none; }
a:hover { text-decoration: underline; }
</style>
</head>
<body>
<main class="card">
	<div class="brand"><i aria-hidden="true"></i>Spotweb</div>
	<h1><?php echo $e($title); ?></h1>

<?php if ($view['mode'] === 'request') { ?>
	<p><?php echo _('Enter your username or e-mail address. We will send you a link to choose a new password.'); ?></p>
	<form method="post" action="?page=resetpassword">
		<input type="hidden" name="resetpasswordform[xsrfid]" value="<?php echo $e($tplHelper->generateXsrfCookie('resetpasswordform')); ?>">
		<label for="identifier"><?php echo _('Username or e-mail address'); ?></label>
		<input type="text" id="identifier" name="resetpasswordform[identifier]" autocomplete="username" required autofocus value="<?php echo $e($view['identifier']); ?>">
		<button type="submit" name="resetpasswordform[submitrequest]" value="1"><?php echo _('Send reset link'); ?></button>
	</form>

<?php } elseif ($view['mode'] === 'sent') { ?>
	<div class="note ok"><?php echo _('If an account with that username or e-mail address exists, we have sent a link to its e-mail address. The link works for 60 minutes.'); ?></div>
	<p><?php echo _('No e-mail? Check your spam folder, or try again in a few minutes.'); ?></p>

<?php } elseif ($view['mode'] === 'choose') { ?>
	<p><?php echo sprintf(_('For the account %s.'), '<strong>'.$e($view['username']).'</strong>'); ?></p>
<?php if (!empty($view['errors'])) { ?>
	<div class="note err" role="alert"><ul><?php foreach ($view['errors'] as $error) { echo '<li>'.$e(strip_tags($error)).'</li>'; } ?></ul></div>
<?php } ?>
	<form method="post" action="?page=resetpassword&amp;token=<?php echo $e(rawurlencode($view['token'])); ?>">
		<input type="hidden" name="resetpasswordform[xsrfid]" value="<?php echo $e($tplHelper->generateXsrfCookie('resetpasswordform')); ?>">
		<input type="text" name="username" value="<?php echo $e($view['username']); ?>" autocomplete="username" hidden>
		<label for="password1"><?php echo _('New password'); ?></label>
		<input type="password" id="password1" name="resetpasswordform[password1]" autocomplete="new-password" minlength="<?php echo Services_User_PasswordReset::MIN_PASSWORD; ?>" required autofocus>
		<label for="password2"><?php echo _('Repeat new password'); ?></label>
		<input type="password" id="password2" name="resetpasswordform[password2]" autocomplete="new-password" minlength="<?php echo Services_User_PasswordReset::MIN_PASSWORD; ?>" required>
		<button type="submit" name="resetpasswordform[submitreset]" value="1"><?php echo _('Save password'); ?></button>
	</form>

<?php } elseif ($view['mode'] === 'done') { ?>
	<div class="note ok"><?php echo _('Your password has been changed. You have been signed out everywhere; log in with your new password.'); ?></div>

<?php } elseif ($view['mode'] === 'invalid') { ?>
	<div class="note err"><?php echo _('The link is expired, already used, or not complete. Request a new one below.'); ?></div>
	<div class="links"><a href="?page=resetpassword"><?php echo _('Request a new link'); ?></a></div>

<?php } else { ?>
	<p><?php echo _('The administrator turned off password reset by e-mail. Ask the administrator for a new password.'); ?></p>
<?php } ?>

	<div class="links"><a href="./"><?php echo _('Back to Spotweb'); ?></a></div>
</main>
</body>
</html>
