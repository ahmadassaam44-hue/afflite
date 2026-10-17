<?php
$qs = $_GET;
$qs['mode'] = 'digital';
header('Location: index.php?' . http_build_query($qs) . '#products');
exit;
