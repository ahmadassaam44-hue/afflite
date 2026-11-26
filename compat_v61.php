<?php
// ============================================================================
// توافق مع النسخة الأحدث من الموقع (v61): دوال العمولات اللي بعض صفحات
// الموقع الحديثة (marketer.php / admin.php) بتنادي عليها ومش موجودة في
// النسخة القديمة من config.php. كل الدوال محمية بـ function_exists.
// ============================================================================

// العمولة المستحقة فعلاً: الطلبات المسلّمة فقط (مش المعلقة ولا الملغية)
if (!function_exists('wf_order_earned')) {
    function wf_order_earned($o) {
        if (!is_array($o) || status_key($o['status'] ?? '') !== 'delivered') return 0.0;
        return (float)($o['m_earn'] ?? 0);
    }
}

// عمولة الطلب بعد استبعاد حالات الإلغاء/الاسترجاع
if (!function_exists('wf_order_commission')) {
    function wf_order_commission($o) {
        if (!is_array($o)) return 0.0;
        if (function_exists('status_reverses_commission') && status_reverses_commission($o['status'] ?? '')) return 0.0;
        return (float)($o['m_earn'] ?? 0);
    }
}

// المبالغ المتنازل عنها لصاحب الموقع (تسوية زيادة سحب)
if (!function_exists('wf_overdraw_waivers')) {
    function wf_overdraw_waivers() { return []; }
}

// إجماليات عمولة مسوق: معلّق / مسلّم / مسحوب / متبقّي
if (!function_exists('wf_marketer_comm_totals')) {
    function wf_marketer_comm_totals($orders, $email = '') {
        $t = ['pending' => 0.0, 'delivered' => 0.0, 'total' => 0.0, 'withdrawn' => 0.0, 'remaining' => 0.0, 'overdrawn' => 0.0, 'waived' => 0.0];
        foreach ((array)$orders as $o) {
            $e = wf_order_commission($o);
            if ($e <= 0) continue;
            if (status_key($o['status'] ?? '') === 'delivered') $t['delivered'] += $e; else $t['pending'] += $e;
        }
        if ($email !== '') {
            foreach ((array)jload('payouts.json', []) as $po) {
                if (strcasecmp((string)($po['email'] ?? $po['user'] ?? ''), (string)$email) === 0
                    && in_array($po['status'] ?? '', ['موافق عليه', 'approved', 'paid'], true)) {
                    $t['withdrawn'] += (float)($po['amount'] ?? 0);
                }
            }
        }
        $t['total'] = round($t['delivered'], 2);
        $t['remaining'] = round(max(0, $t['delivered'] - $t['withdrawn']), 2);
        $t['overdrawn'] = round(max(0, $t['withdrawn'] - $t['delivered']), 2);
        foreach (['pending', 'delivered', 'withdrawn'] as $k) $t[$k] = round($t[$k], 2);
        return $t;
    }
}
