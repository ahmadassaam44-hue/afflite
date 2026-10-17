<?php
// ===== HEVO SMM API wrapper (https://hevoteam.com/api/v2) =====
// المفتاح من config.php (HEVO_API_KEY). كل الدوال ترجع array أو ['error'=>...].
function hevo_key() { return defined('HEVO_API_KEY') ? HEVO_API_KEY : ''; }
function hevo_call($params) {
    $key = hevo_key();
    if (!$key) return ['error' => 'no key'];
    $ch = curl_init('https://hevoteam.com/api/v2');
    curl_setopt_array($ch, [CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(array_merge(['key' => $key], $params)),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code >= 400) return ['error' => 'http ' . $code];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : ['error' => 'bad response'];
}
function hevo_services() { return hevo_call(['action' => 'services']); }
function hevo_add($service, $link, $qty) {
    return hevo_call(['action' => 'add', 'service' => $service, 'link' => $link, 'quantity' => (int)$qty]);
}
function hevo_status($order) { return hevo_call(['action' => 'status', 'order' => $order]); }
function hevo_balance() { return hevo_call(['action' => 'balance']); }
function hevo_refill($order) { return hevo_call(['action' => 'refill', 'order' => $order]); }
function hevo_cancel($orders) {
    return hevo_call(['action' => 'cancel', 'orders' => is_array($orders) ? implode(',', $orders) : $orders]);
}
