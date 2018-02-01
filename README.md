[![GitHub release](https://img.shields.io/github/release/realdigger/SMF-DokuWiki-Bridge.svg)]()
[![Build Status](https://travis-ci.org/realdigger/SMF-DokuWiki-Bridge.svg?branch=master)](https://travis-ci.org/realdigger/SMF-DokuWiki-Bridge)
[![SMF](https://img.shields.io/badge/SMF-2.0-blue.svg?style==flat)](https://simplemachines.org)
[![DokuWiki](https://img.shields.io/badge/DokuWiki-Frusterick%20Manners-blue.svg?style==flat)](https://www.dokuwiki.org)

# SMF DokuWiki Auth Bridge (authsmf20) [![Tweet](https://img.shields.io/twitter/url/http/shields.io.svg?style=social)](https://twitter.com/intent/tweet?text=SMF%20DokuWiki%20Auth%20Bridge&url=https://github.com/realdigger/SMF-DokuWiki-Bridge&hashtags=smf,dokuwiki,)

* **Author:** digger [http://mysmf.net](http://mysmf.net)
* **License:** GPL 2
* **Compatible with:** SMF 2.0, DokuWiki Frusterick Manners
* **Languages:** English, Russian

## Installation  
Download latest release tar.gz file from [releases page](https://github.com/realdigger/SMF-DokuWiki-Bridge/releases).  
Unpack authsmf20 and upload to DokuWiki lib/plugins dir.  
Set correct path to SMF in DokuWiki Authsmf20 plugin settings.

## Description
DokuWiki plugin used to authenticate users using SMF 2.0 backend.
Compatible with PHP7 and latest DokuWiki versions. Plugin uses SMF SSI API and work with any DB backend supported by SMF.

If you install this plugin manually, make sure it is installed in
lib/plugins/authsmf20/ - if the folder is called different it
will not work!

Please refer to http://www.dokuwiki.org/plugins for additional info
on how to install plugins in DokuWiki.


 You must de-activate the following Dokuwiki features. Please refer to Configuration Setting: disableactions.

    Register
    Update profile
    Set new password
    Delete own account
