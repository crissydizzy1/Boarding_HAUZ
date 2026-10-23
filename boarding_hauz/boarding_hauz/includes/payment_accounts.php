<?php

function paymentAccountSetting(string $name, string $fallback = ''): string
{
    $value = getenv($name);
    return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
}

return [
    'GCash' => [
        'label' => 'GCash',
        'account_name' => paymentAccountSetting('PAYMENT_GCASH_ACCOUNT_NAME', 'Juan Dela Cruz / Boarding Hauz (placeholder)'),
        'account_number' => paymentAccountSetting('PAYMENT_GCASH_ACCOUNT_NUMBER', '0917-XXX-XXXX (placeholder)'),
        'qr_path' => paymentAccountSetting('PAYMENT_GCASH_QR', 'assets/images/payment-qr-placeholder.svg'),
        'is_configured' => paymentAccountSetting('PAYMENT_GCASH_ACCOUNT_NAME') !== ''
            && paymentAccountSetting('PAYMENT_GCASH_ACCOUNT_NUMBER') !== ''
            && paymentAccountSetting('PAYMENT_GCASH_QR') !== '',
    ],
    'Maya' => [
        'label' => 'Maya',
        'account_name' => paymentAccountSetting('PAYMENT_MAYA_ACCOUNT_NAME', 'Boarding Hauz (configure before use)'),
        'account_number' => paymentAccountSetting('PAYMENT_MAYA_ACCOUNT_NUMBER', 'Not configured'),
        'qr_path' => paymentAccountSetting('PAYMENT_MAYA_QR', 'assets/images/payment-qr-placeholder.svg'),
        'is_configured' => paymentAccountSetting('PAYMENT_MAYA_ACCOUNT_NAME') !== ''
            && paymentAccountSetting('PAYMENT_MAYA_ACCOUNT_NUMBER') !== ''
            && paymentAccountSetting('PAYMENT_MAYA_QR') !== '',
    ],
    'Bank Transfer' => [
        'label' => 'Bank (BPI)',
        'account_name' => paymentAccountSetting('PAYMENT_BPI_ACCOUNT_NAME', 'Boarding Hauz (configure before use)'),
        'account_number' => paymentAccountSetting('PAYMENT_BPI_ACCOUNT_NUMBER', 'Not configured'),
        'qr_path' => paymentAccountSetting('PAYMENT_BPI_QR', 'assets/images/payment-qr-placeholder.svg'),
        'is_configured' => paymentAccountSetting('PAYMENT_BPI_ACCOUNT_NAME') !== ''
            && paymentAccountSetting('PAYMENT_BPI_ACCOUNT_NUMBER') !== ''
            && paymentAccountSetting('PAYMENT_BPI_QR') !== '',
    ],
];
