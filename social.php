<?php
$qs = $_GET;
$qs['mode'] = 'social';
header('Location: index.php?' . http_build_query($qs) . '#products');
exit;
