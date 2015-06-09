# Quick RSS Feed Sync

This is a tiny (one file!), yet very extensible plugin for WordPress and WP-CLI command that allows you to fetch a feed and save it as posts to your WordPress database. 

It uses the embedded SimplePie class and I did it because I couldn't find any other plugin to do this simply and without huge configuration pages that don't really work that well. Besides, I wanted to avoid using wp-cron, so a wp-cli command can be set in the real crontab.

# TODO
- Usage and configuration in README.md
- Allow per-feed and global config parameters