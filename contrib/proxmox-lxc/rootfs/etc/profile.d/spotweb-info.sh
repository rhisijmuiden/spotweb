# Show how to reach Spotweb when root logs in on the console or over SSH.
if [ "$(id -u)" = 0 ] && [ -n "${PS1:-}" ] && [ -x /usr/local/sbin/spotweb-info ]; then
    /usr/local/sbin/spotweb-info
fi
