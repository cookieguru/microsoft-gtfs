Microsoft Shuttles GTFS
=======================

This is a small PHP script that generates a 
[General Transit Feed Specification (GTFS)](https://developers.google.com/transit/gtfs/GTFS)
zip file for the Microsoft shuttles.  All information is gathered from the
publicly-accessible [msshuttle.mobi](https://msshuttle.mobi/) website.

Requirements
------------
PHP 5.4+

Installation
------------
Clone or download the repo, then run `composer install`

Usage
-----
Run `builder.php` from the command line or through a browser.  A file named
`gtfs_date.zip` will be placed in the `dist` folder.

License
-------
MIT
