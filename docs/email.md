# E-mail

Spotweb sends welcome e-mails, password reset links and notifications.
Set it up under **Admin > E-mail**.

## Mail server (SMTP)

| Setting | What to fill in |
| --- | --- |
| Sender email address | An address of a domain your mail server may send for, e.g. `spotweb@example.com` |
| Hostname | Your SMTP server, e.g. `smtp.example.com` |
| Encryption | **STARTTLS** (port 587, recommended) or **SSL/TLS** (port 465). *None* only on a trusted network |
| Check the server certificate | Keep on. Turn off only for a self-signed certificate |
| Log in to the server | Username and password of the mailbox. The password is never shown again; leave the field empty to keep it |

Connections use TLS 1.2 or newer. Every mail goes out as UTF-8 with a plain
text and an HTML part. With SMTP turned off Spotweb falls back to PHP's
`mail()`, which often lands in spam.

**Send test e-mail** uses the values on the page, also before you save them,
and shows the conversation with the mail server (passwords hidden), so you
can see why a mail fails.

## DKIM (optional)

Most mail servers sign mail already. If yours does not, generate a key pair,
paste the private key (PEM) with a domain and selector, and publish the public
key as a TXT record at `<selector>._domainkey.<domain>`.

## Password reset and welcome e-mails

With **Let users reset a forgotten password by e-mail** on (the default), the
login screen shows *Forgot password?*. The link in the e-mail works once and
for 60 minutes, and signs the user out everywhere after the change. New users
get a link to choose their own password (valid for 48 hours) instead of a
password in plain text.

Links point to the address Spotweb is reached on (`spotweburl`), so open the
admin page via the public address, for example behind Cloudflare.
