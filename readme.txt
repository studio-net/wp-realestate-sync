=== WP Real Estate Sync ===
Contributors: studionet
Tags: realestate, properties, immobilier, synchronization
Requires at least: 3.8
Tested up to: 3.9
Stable tag: trunk
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Synchronize your properties from your real estate software to your Wordpress website !

== Description ==

You can synchronize your real estate Wordpress site with the Gedeon API : http://api.gedeon.im/doc.

Currently supported themes are :
* WPCasa based (http://wpcasa.com)
* Realto (http://themeforest.net/item/realto-wordpress-theme-for-real-estate-companies/6801549)
* Decorum (http://themeshift.com/theme/decorum/)

More themes are coming soon !


== Installation ==

1. Unzip plugin to the `/wp-content/plugins/` directory
1. Or, install it through Wordpress plugin repo.
1. Activate the plugin through the 'Plugins' menu in WordPress

== Frequently Asked Questions ==

= The theme I use is not supported, what can I do ? =

Let us know at wordpress@lsi.im that you are interrested by this particular theme, we will then develop a connector as soon as possible, (after validating the theme).

If your an adventurer, you can develop yourself the compatibility by forking https://github.com/studio-net/wp-realestate-sync

= I'm a theme creator, I want to be compatible with your plugin, what can I do? =

1. Take a look at https://github.com/studio-net/wp-realestate-sync to understand how plugin work
1. Fork the project and develop your own connector.
1. Or, if your are not a developer, let us know at wordpress@lsi.im that you'd like to be compatible, we will help you.

= But, is it a realtime connection with your API ? =

No. Most of the real estate themes stores properties in custom posts. The plugin just create custom post in wordpress by following theme rules.

A cron import properties at chosen rythm (hourly, daily, ...).

= Okay, but it only synchronises properties =

No, for example it adds a DPE widget (french-specific energy consumption indicator) to WPCasa based themes. Others features like that are planned.

