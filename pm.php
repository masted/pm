#!/usr/bin/php
<?php

define('PROJECT_PATH', __DIR__);
require __DIR__.'/common-init.php';
Cli::storeCommand(__DIR__.'/logs');
new PmManager($_SERVER['argv']);