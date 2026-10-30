<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/payment_due.php';
requireRole('tenant');

$tenant_title = 'Payments';
$userId = (int) $_SESSION['user_id'];
$error = '';
$tenant = null;
$landlordPaymentSettings = null;
try {
    $stmt = $conn->prepare("
        SELECT t.id AS tenant_id, t.branch_id, t.full_name, t.move_in_date, t.status AS tenant_status,
               r.id AS room_id, r.room_number, r.room_type, r.monthly_rate
        FROM tenants t
        LEFT JOIN rooms r ON r.id = t.room_id
        WHERE t.user_id = ?
        ORDER BY (LOWER(t.status) = 'active') DESC, t.id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        throw new mysqli_sql_exception($conn->error);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Tenant payment profile query failed: ' . $exception->getMessage());
    $error = 'Your payment profile could not be loaded. Please contact management.';
}

if ($tenant && !empty($tenant['branch_id'])) {
    try {
        $channelStmt = $conn->prepare("
            SELECT u.gcash_account_name, u.gcash_number,
                   u.maya_account_name, u.maya_number,
                   u.bank_name, u.bank_account_name, u.bank_account_number
            FROM branches b
            INNER JOIN users u ON u.id = b.landlord_id AND u.role = 'landlord'
            WHERE b.id = ?
            LIMIT 1
        ");
        $branchId = (int) $tenant['branch_id'];
        $channelStmt->bind_param('i', $branchId);
        $channelStmt->execute();
        $landlordPaymentSettings = $channelStmt->get_result()->fetch_assoc() ?: null;
        $channelStmt->close();
    } catch (mysqli_sql_exception $exception) {
        error_log('Tenant landlord payment channel query failed: ' . $exception->getMessage());
        $error = 'The property payment details could not be loaded. Contact your landlord before transferring funds.';
    }
}

$success = ($_GET['submitted'] ?? null) === '1' ? 'Your payment proof was submitted and is pending verification.' : '';
$proofFieldsAvailable = false;
$columnResult = $conn->query("
    SELECT COUNT(*) AS available_columns
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND COLUMN_NAME IN ('reference_number', 'proof_path')
");
if ($columnResult) {
    $proofFieldsAvailable = (int) $columnResult->fetch_assoc()['available_columns'] === 2;
    $columnResult->free();
}
if (!$proofFieldsAvailable) {
    $error = 'Payment receipt submission is unavailable until the payment proof database migration is installed.';
}
$paymentMethods = ['Cash', 'GCash', 'Maya', 'Bank Transfer'];
$paymentMethodLabels = [
    'Cash' => 'Cash',
    'GCash' => 'GCash',
    'Maya' => 'Maya',
    'Bank Transfer' => 'Bank Transfer',
];
$paymentChannelDetails = [
    'Cash' => [
        'label' => 'Cash payment',
        'account_name' => '',
        'account_number' => '',
    ],
    'GCash' => [
        'label' => 'GCash',
        'account_name' => (string) ($landlordPaymentSettings['gcash_account_name'] ?? ''),
        'account_number' => (string) ($landlordPaymentSettings['gcash_number'] ?? ''),
    ],
    'Maya' => [
        'label' => 'Maya',
        'account_name' => (string) ($landlordPaymentSettings['maya_account_name'] ?? ''),
        'account_number' => (string) ($landlordPaymentSettings['maya_number'] ?? ''),
    ],
    'Bank Transfer' => [
        'label' => (string) ($landlordPaymentSettings['bank_name'] ?? '') !== ''
            ? (string) $landlordPaymentSettings['bank_name']
            : 'Bank transfer',
        'account_name' => (string) ($landlordPaymentSettings['bank_account_name'] ?? ''),
        'account_number' => (string) ($landlordPaymentSettings['bank_account_number'] ?? ''),
    ],
];
$currentDue = null;
$payments = [];
$searchReference = is_string($_GET['reference'] ?? null) ? trim($_GET['reference']) : '';
$searchReference = substr($searchReference, 0, 100);

if ($tenant && !empty($tenant['room_id'])) {
    $currentDue = getCurrentMonthPaymentDue(
        $conn,
        (int) $tenant['tenant_id'],
        (float) $tenant['monthly_rate'],
        $tenant['move_in_date'] ?: null
    );
}

if (empty($_SESSION['tenant_payments_csrf'])) {
    $_SESSION['tenant_payments_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $proofFieldsAvailable) {
    $postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    $paymentMethod = is_string($_POST['payment_method'] ?? null) ? $_POST['payment_method'] : '';
    $referenceNumber = is_string($_POST['reference_number'] ?? null) ? trim($_POST['reference_number']) : '';
    $upload = $_FILES['proof_file'] ?? null;

    if (!hash_equals($_SESSION['tenant_payments_csrf'], $postedToken)) {
        $error = 'Your session could not be verified. Refresh the page and try again.';
    } elseif (!$tenant || empty($tenant['room_id']) || empty($tenant['branch_id']) || (float) $tenant['monthly_rate'] <= 0) {
        $error = 'A room with a valid monthly rate must be assigned before submitting a payment.';
    } elseif (!$currentDue || (float) $currentDue['amount_due'] <= 0) {
        $error = 'There is no unpaid room-rent amount available for submission.';
    } elseif ($currentDue['has_pending_submission']) {
        $error = 'A payment for this billing cycle is already awaiting verification.';
    } elseif (!in_array($paymentMethod, $paymentMethods, true)) {
        $error = 'Choose a valid payment method.';
    } elseif ($referenceNumber === '' || strlen($referenceNumber) > 100) {
        $error = 'Enter a reference number of 1 to 100 characters.';
    } elseif (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['name'])) {
        $error = 'Select a receipt file to upload.';
    } elseif ((int) $upload['error'] !== UPLOAD_ERR_OK) {
        $error = 'The receipt upload failed. Choose a file up to 10 MB and try again.';
    } elseif (!is_uploaded_file((string) $upload['tmp_name'])
        || (int) ($upload['size'] ?? 0) <= 0
        || (int) $upload['size'] > 10 * 1024 * 1024) {
        $error = 'Receipt files must be valid and no larger than 10 MB.';
    } else {
        $extension = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file((string) $upload['tmp_name']);
        $allowedProofTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
        ];
        $validFile = isset($allowedProofTypes[$extension])
            && $mimeType === $allowedProofTypes[$extension];
        if ($validFile && $extension !== 'pdf') {
            $image = getimagesize((string) $upload['tmp_name']);
            $validFile = is_array($image) && ($image['mime'] ?? '') === $mimeType;
        } elseif ($validFile) {
            $handle = fopen((string) $upload['tmp_name'], 'rb');
            $signature = $handle === false ? false : fread($handle, 5);
            if ($handle !== false) {
                fclose($handle);
            }
            $validFile = $signature === '%PDF-';
        }

        if (!$validFile) {
            $error = 'Upload a valid JPG, PNG, WebP, or PDF receipt.';
        } else {
            $proofDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'payment-proofs';
            $newProofPath = null;
            try {
                if (!is_dir($proofDirectory)
                    && !mkdir($proofDirectory, 0755, true)
                    && !is_dir($proofDirectory)) {
                    throw new RuntimeException('The receipt storage folder could not be created.');
                }
                if (!is_writable($proofDirectory)) {
                    throw new RuntimeException('The receipt storage folder is not writable.');
                }

                $conn->begin_transaction();
                $amountPaid = (float) $currentDue['amount_due'];
                $paymentDate = date('Y-m-d');
                $tenantId = (int) $tenant['tenant_id'];
                $pendingStatus = 'Pending';
                $stmt = $conn->prepare('
                    INSERT INTO payments
                        (branch_id, tenant_id, amount_paid, payment_date, payment_method, reference_number, proof_path, status)
                    VALUES (?, ?, ?, ?, ?, ?, NULL, ?)
                ');
                $branchId = (int) $tenant['branch_id'];
                $stmt->bind_param('iidssss', $branchId, $tenantId, $amountPaid, $paymentDate, $paymentMethod, $referenceNumber, $pendingStatus);
                $stmt->execute();
                $paymentId = (int) $conn->insert_id;
                $stmt->close();

                $fileName = 'payment-' . $paymentId . '-' . bin2hex(random_bytes(16)) . '.' . $extension;
                $destination = $proofDirectory . DIRECTORY_SEPARATOR . $fileName;
                if (!move_uploaded_file((string) $upload['tmp_name'], $destination)) {
                    throw new RuntimeException('The receipt could not be stored.');
                }
                $newProofPath = $destination;
                $storedProofPath = 'uploads/payment-proofs/' . $fileName;
                $stmt = $conn->prepare('UPDATE payments SET proof_path = ? WHERE id = ? AND tenant_id = ?');
                $stmt->bind_param('sii', $storedProofPath, $paymentId, $tenantId);
                $stmt->execute();
                $proofUpdated = $stmt->affected_rows === 1;
                $stmt->close();
                if (!$proofUpdated) {
                    throw new RuntimeException('The receipt record could not be finalized.');
                }

                $_SESSION['tenant_payments_csrf'] = bin2hex(random_bytes(32));
                $conn->commit();
                header('Location: tenant_payments.php?submitted=1');
                exit;
            } catch (RuntimeException $exception) {
                $conn->rollback();
                if ($newProofPath !== null && is_file($newProofPath) && !unlink($newProofPath)) {
                    error_log('Could not remove uncommitted payment receipt: ' . $newProofPath);
                }
                $error = $exception->getMessage();
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                if ($newProofPath !== null && is_file($newProofPath) && !unlink($newProofPath)) {
                    error_log('Could not remove uncommitted payment receipt: ' . $newProofPath);
                }
                error_log('Tenant payment submission failed: ' . $exception->getMessage());
                $error = 'The payment submission could not be saved. Confirm the payment proof database fields are installed and try again.';
            }
        }
    }
}

if ($tenant && $proofFieldsAvailable) {
    $tenantId = (int) $tenant['tenant_id'];
    $stmt = $conn->prepare('
        SELECT id, amount_paid, payment_date, payment_method, reference_number, proof_path, status
        FROM payments
        WHERE tenant_id = ? AND (? = \'\' OR reference_number LIKE ?)
        ORDER BY payment_date DESC, id DESC
        LIMIT 100
    ');
    $searchLike = '%' . $searchReference . '%';
    $stmt->bind_param('iss', $tenantId, $searchReference, $searchLike);
    $stmt->execute();
    $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

function paymentStatusPresentation(string $status): array
{
    return match ($status) {
        'Paid', 'Verified' => ['Verified & Paid', 'bg-[#bcedd6]/70 text-[#002116] border-[#a1d1bb]'],
        'Pending' => ['Pending Verification', 'bg-[#fff8e1] text-[#8a6500] border-[#ffe082]'],
        'Rejected', 'Overdue' => [$status, 'bg-[#ffdad6] text-[#93000a] border-[#eab4ae]'],
        default => [$status, 'bg-white/70 text-[#02353C] border-outline-variant/50'],
    };
}

$tenant_page = basename($_SERVER['PHP_SELF']);
$tenant_user = currentUser();
$tenant_unread_count = 0;
$unreadStmt = $conn->prepare('SELECT COUNT(*) AS unread_count FROM messages WHERE receiver_id = ? AND is_read = 0');
if ($unreadStmt) {
    $unreadStmt->bind_param('i', $userId);
    $unreadStmt->execute();
    $unreadRow = $unreadStmt->get_result()->fetch_assoc();
    $tenant_unread_count = (int) ($unreadRow['unread_count'] ?? 0);
    $unreadStmt->close();
}
?>
<!DOCTYPE html>

<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Payments | Boarding Hauz Tenant Portal</title>
<!-- Google Fonts: Plus Jakarta Sans -->
<link href="https://fonts.googleapis.com" rel="preconnect"/>
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&amp;display=swap" rel="stylesheet"/>
<link href="/boarding_hauz/assets/css/style.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/style.css') ?>" rel="stylesheet"/>
<!-- Material Symbols Outlined -->
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<!-- Tailwind CSS v3 with Container Queries -->
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<!-- Tailwind Configuration verbatim from Design System -->
<script id="tailwind-config">
    tailwind.config = {
      darkMode: "class",
      theme: {
        extend: {
          "colors": {
            "tertiary": "#5e5c54",
            "primary": "#91472f",
            "outline-variant": "#dac1ba",
            "error": "#ba1a1a",
            "inverse-primary": "#ffb59f",
            "on-tertiary": "#ffffff",
            "on-error-container": "#93000a",
            "secondary-fixed-dim": "#a1d1bb",
            "on-surface": "#111c2d",
            "surface-variant": "#d8e3fb",
            "tertiary-fixed": "#e7e2d8",
            "on-tertiary-fixed": "#1d1c16",
            "surface-container-highest": "#d8e3fb",
            "background": "#f9f9ff",
            "on-primary": "#ffffff",
            "primary-container": "#af5e45",
            "surface-container-low": "#f0f3ff",
            "outline": "#87736d",
            "on-error": "#ffffff",
            "on-secondary-fixed": "#002116",
            "on-tertiary-fixed-variant": "#49473f",
            "on-surface-variant": "#54433e",
            "on-primary-container": "#fffbff",
            "surface-container-lowest": "#ffffff",
            "tertiary-fixed-dim": "#cbc6bc",
            "on-primary-fixed": "#3a0a00",
            "primary-fixed-dim": "#ffb59f",
            "on-secondary": "#ffffff",
            "surface-dim": "#cfdaf2",
            "on-secondary-container": "#406d5b",
            "secondary": "#3a6755",
            "secondary-container": "#bcedd6",
            "primary-fixed": "#ffdbd0",
            "error-container": "#ffdad6",
            "on-tertiary-container": "#fffbff",
            "on-background": "#111c2d",
            "surface-container-high": "#dee8ff",
            "tertiary-container": "#77746c",
            "secondary-fixed": "#bcedd6",
            "on-secondary-fixed-variant": "#214f3e",
            "surface-container": "#e7eeff",
            "inverse-surface": "#263143",
            "surface-bright": "#f9f9ff",
            "surface": "#f9f9ff",
            "on-primary-fixed-variant": "#76321d",
            "inverse-on-surface": "#ecf1ff",
            "surface-tint": "#944932"
          },
          "borderRadius": {
            "DEFAULT": "0.25rem",
            "lg": "0.5rem",
            "xl": "0.75rem",
            "full": "9999px"
          },
          "spacing": {
            "gutter-mobile": "1rem",
            "margin": "3rem",
            "space-xl": "2.5rem",
            "space-sm": "0.5rem",
            "space-xs": "0.25rem",
            "margin-mobile": "1.25rem",
            "space-lg": "1.5rem",
            "gutter": "1.5rem",
            "space-md": "1rem"
          },
          "fontFamily": {
            "label-lg": ["Plus Jakarta Sans"],
            "headline-xl": ["Plus Jakarta Sans"],
            "label-md": ["Plus Jakarta Sans"],
            "headline-xl-mobile": ["Plus Jakarta Sans"],
            "label-sm": ["Plus Jakarta Sans"],
            "body-lg": ["Plus Jakarta Sans"],
            "headline-md": ["Plus Jakarta Sans"],
            "body-md": ["Plus Jakarta Sans"],
            "headline-sm": ["Plus Jakarta Sans"],
            "headline-lg": ["Plus Jakarta Sans"],
            "body-sm": ["Plus Jakarta Sans"],
            "headline-lg-mobile": ["Plus Jakarta Sans"]
          },
          "fontSize": {
            "label-lg": ["14px", { "lineHeight": "20px", "letterSpacing": "0.01em", "fontWeight": "600" }],
            "headline-xl": ["56px", { "lineHeight": "64px", "letterSpacing": "-0.03em", "fontWeight": "700" }],
            "label-md": ["12px", { "lineHeight": "16px", "letterSpacing": "0.02em", "fontWeight": "600" }],
            "headline-xl-mobile": ["36px", { "lineHeight": "44px", "letterSpacing": "-0.02em", "fontWeight": "700" }],
            "label-sm": ["11px", { "lineHeight": "14px", "letterSpacing": "0.04em", "fontWeight": "500" }],
            "body-lg": ["18px", { "lineHeight": "28px", "fontWeight": "400" }],
            "headline-md": ["28px", { "lineHeight": "36px", "letterSpacing": "-0.01em", "fontWeight": "600" }],
            "body-md": ["16px", { "lineHeight": "26px", "fontWeight": "400" }],
            "headline-sm": ["20px", { "lineHeight": "28px", "letterSpacing": "-0.005em", "fontWeight": "600" }],
            "headline-lg": ["40px", { "lineHeight": "48px", "letterSpacing": "-0.02em", "fontWeight": "600" }],
            "body-sm": ["14px", { "lineHeight": "22px", "fontWeight": "400" }],
            "headline-lg-mobile": ["28px", { "lineHeight": "36px", "letterSpacing": "-0.015em", "fontWeight": "600" }]
          }
        }
      }
    }
  </script>
<style>
    .material-symbols-outlined {
      font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
      display: inline-block;
      vertical-align: middle;
      line-height: 1;
    }
    .material-symbols-outlined.fill-icon {
      font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 24;
    }
    /* Ambient geometric background canvas styling */
    body {
      background-color: #f0fdf9;
      background-image: 
        radial-gradient(at 0% 0%, rgba(193, 246, 237, 0.7) 0px, transparent 50%),
        radial-gradient(at 100% 10%, rgba(161, 209, 187, 0.45) 0px, transparent 55%),
        radial-gradient(at 50% 80%, rgba(220, 248, 242, 0.6) 0px, transparent 60%),
        linear-gradient(rgba(46, 175, 125, 0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(46, 175, 125, 0.04) 1px, transparent 1px);
      background-size: 100% 100%, 100% 100%, 100% 100%, 36px 36px, 36px 36px;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .glass-card {
      background: rgba(255, 255, 255, 0.90);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border: 1px solid rgba(255, 255, 255, 0.85);
      box-shadow: 0 4px 12px -2px rgba(2, 53, 60, 0.04), 0 16px 32px -4px rgba(2, 53, 60, 0.06);
    }
    body.payment-page-body {
      background: #effbfc;
      background-image: none;
      color: #102d32;
    }
    .rent-page {
      width: min(100% - 40px, 1240px);
      margin: 0 auto;
      padding: 34px 0 64px;
      color: #102d32;
      font-family: Georgia, 'Times New Roman', serif;
    }
    .rent-page-header {
      margin-bottom: 38px;
      text-align: center;
    }
    .rent-page-badge {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 5px 13px;
      border-radius: 999px;
      background: #d1f5f8;
      color: #15545a;
      font: 500 12px/1.3 'Plus Jakarta Sans', sans-serif;
    }
    .rent-page-header h1 {
      margin: 10px 0 4px;
      color: #102d32;
      font: 700 clamp(28px, 4vw, 38px)/1.2 Georgia, 'Times New Roman', serif;
    }
    .rent-page-header p {
      margin: 0;
      color: #53696d;
      font: 14px/1.6 'Plus Jakarta Sans', sans-serif;
    }
    .rent-alert {
      margin-bottom: 14px;
      padding: 12px 15px;
      border: 1px solid #eab4ae;
      border-radius: 9px;
      background: #ffdad6;
      color: #93000a;
      font: 13px/1.5 'Plus Jakarta Sans', sans-serif;
    }
    .rent-alert.success {
      border-color: #a1d1bb;
      background: #e4f7ef;
      color: #176b59;
    }
    .rent-grid {
      display: grid;
      grid-template-columns: minmax(0, 1.35fr) minmax(340px, 0.95fr);
      align-items: stretch;
      gap: 24px;
    }
    .rent-card {
      min-width: 0;
      padding: 24px;
      border: 1px solid #e0ebed;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 5px 18px rgba(15, 70, 75, .035);
    }
    .rent-card-heading {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 18px;
    }
    .rent-card-title {
      display: flex;
      align-items: center;
      gap: 11px;
      min-width: 0;
    }
    .rent-step-number {
      display: grid;
      width: 31px;
      height: 31px;
      flex: 0 0 31px;
      place-items: center;
      border-radius: 50%;
      background: #e5f3ee;
      color: #08734f;
      font: 700 13px 'Plus Jakarta Sans', sans-serif;
    }
    .rent-card-title h2 {
      margin: 0;
      color: #102d32;
      font: 700 21px/1.3 Georgia, 'Times New Roman', serif;
    }
    .rent-step-caption {
      color: #51666a;
      font: 11px 'Plus Jakarta Sans', sans-serif;
      white-space: nowrap;
    }
    .rent-amount-panel {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 15px;
      margin-bottom: 18px;
      padding: 17px 19px;
      border-radius: 10px;
      background: linear-gradient(110deg, #d9f7fa, #c8f1f6);
    }
    .rent-amount-caption {
      display: block;
      margin-bottom: 3px;
      color: #536c70;
      font: 11px 'Plus Jakarta Sans', sans-serif;
      letter-spacing: .02em;
      text-transform: uppercase;
    }
    .rent-amount-value {
      color: #102d32;
      font: 700 clamp(25px, 3vw, 32px)/1.2 Georgia, 'Times New Roman', serif;
    }
    .rent-due-chip {
      flex: 0 0 auto;
      padding: 7px 11px;
      border-radius: 7px;
      background: #b6eae8;
      color: #15545a;
      font: 12px 'Plus Jakarta Sans', sans-serif;
    }
    .rent-method-tabs {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 5px;
      margin-bottom: 18px;
      padding: 5px;
      border-radius: 9px;
      background: #d9f7fa;
    }
    .rent-method-tab {
      display: flex;
      min-width: 0;
      min-height: 42px;
      align-items: center;
      justify-content: center;
      gap: 6px;
      padding: 7px 5px;
      border: 0;
      border-radius: 7px;
      background: transparent;
      color: #51666a;
      cursor: pointer;
      font: 500 12px 'Plus Jakarta Sans', sans-serif;
    }
    .rent-method-tab[aria-pressed="true"] {
      background: #29a77e;
      color: #fff;
      box-shadow: 0 2px 6px rgba(10, 92, 66, .16);
    }
    .rent-method-tab:focus-visible,
    .rent-submit:focus-visible,
    .rent-file-label:focus-within {
      outline: 3px solid #65c9bd;
      outline-offset: 2px;
    }
    .rent-channel-panel {
      display: grid;
      grid-template-columns: minmax(120px, .75fr) minmax(0, 1.35fr);
      align-items: center;
      gap: 16px;
      min-height: 185px;
      padding: 18px;
      border-radius: 10px;
      background: linear-gradient(120deg, #effcfd, #d7faf8);
    }
    .rent-channel-panel[hidden] {
      display: none;
    }
    .rent-qr-placeholder {
      display: grid;
      min-height: 145px;
      place-items: center;
      padding: 12px;
      border: 1px solid #d7e9e8;
      border-radius: 10px;
      background: #fff;
      color: #477077;
      text-align: center;
      font: 11px/1.5 'Plus Jakarta Sans', sans-serif;
    }
    .rent-qr-placeholder .material-symbols-outlined {
      display: block;
      margin-bottom: 5px;
      color: #13836d;
      font-size: 31px;
    }
    .rent-channel-details {
      min-width: 0;
      padding: 13px 14px;
      border: 1px solid #e0eded;
      border-radius: 8px;
      background: rgba(255,255,255,.85);
      font: 12px/1.5 'Plus Jakarta Sans', sans-serif;
    }
    .rent-channel-label {
      display: flex;
      align-items: center;
      gap: 7px;
      margin: 0 0 9px;
      color: #12614f;
      font-size: 13px;
      font-weight: 700;
    }
    .rent-detail-row {
      display: flex;
      justify-content: space-between;
      gap: 10px;
      padding: 4px 0;
      color: #52696d;
    }
    .rent-detail-row strong {
      color: #18383d;
      text-align: right;
      overflow-wrap: anywhere;
    }
    .rent-detail-note {
      margin: 7px 0 0;
      color: #687c7f;
      font-size: 11px;
    }
    .rent-form {
      display: grid;
      gap: 14px;
    }
    .rent-field label,
    .rent-file-heading {
      display: block;
      margin-bottom: 6px;
      color: #233f43;
      font: 12px 'Plus Jakarta Sans', sans-serif;
    }
    .rent-field input,
    .rent-field select {
      display: block;
      width: 100%;
      min-height: 43px;
      padding: 10px 12px;
      border: 1px solid #c9e2e5;
      border-radius: 7px;
      background: #dff8fb;
      color: #17393d;
      font: 13px 'Plus Jakarta Sans', sans-serif;
    }
    .rent-field input:focus,
    .rent-field select:focus {
      border-color: #29a77e;
      outline: 3px solid rgba(41,167,126,.15);
    }
    .rent-file-label {
      display: grid;
      min-height: 112px;
      place-items: center;
      padding: 12px;
      border: 1px dashed #5eafa8;
      border-radius: 9px;
      background: #effbfc;
      color: #29484b;
      cursor: pointer;
      text-align: center;
      font: 12px/1.5 'Plus Jakarta Sans', sans-serif;
    }
    .rent-file-label .material-symbols-outlined {
      display: grid;
      width: 37px;
      height: 37px;
      place-items: center;
      margin: 0 auto 5px;
      border-radius: 50%;
      background: #d2f7ed;
      color: #15805f;
      font-size: 21px;
    }
    .rent-file-label small {
      display: block;
      color: #62797c;
      font-size: 10px;
    }
    .rent-file-input {
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      clip-path: inset(50%);
    }
    .rent-submit {
      display: flex;
      width: 100%;
      min-height: 48px;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin-top: 2px;
      padding: 11px 16px;
      border: 0;
      border-radius: 8px;
      background: #29a77e;
      color: #fff;
      cursor: pointer;
      font: 700 13px 'Plus Jakarta Sans', sans-serif;
    }
    .rent-submit:hover:not(:disabled) {
      background: #218f6c;
    }
    .rent-submit:disabled {
      cursor: not-allowed;
      opacity: .55;
    }
    .rent-history {
      margin-top: 38px;
    }
    .rent-page .glass-card {
      background: #fff;
      border-color: #e0ebed;
      box-shadow: 0 5px 18px rgba(15, 70, 75, .035);
    }
    @media (max-width: 820px) {
      .rent-grid {
        grid-template-columns: 1fr;
      }
      .rent-page-header {
        margin-bottom: 25px;
      }
    }
    @media (max-width: 520px) {
      .rent-page {
        width: min(100% - 24px, 480px);
        padding-top: 24px;
      }
      .rent-card {
        padding: 17px;
      }
      .rent-card-title h2 {
        font-size: 18px;
      }
      .rent-method-tabs {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      .rent-channel-panel {
        grid-template-columns: 1fr;
      }
      .rent-qr-placeholder {
        min-height: 94px;
      }
    }
  </style>
</head>
<body class="tenant-portal payment-page-body text-[#02353C] min-h-screen flex flex-col antialiased selection:bg-secondary-fixed selection:text-[#02353C]">
<header class="site-header tenant-site-header">
    <div class="header-container">
        <a href="/boarding_hauz/index.php" class="brand"><img class="brand-logo" src="/boarding_hauz/assets/images/boarding-hauz-icon.png" alt="">Boarding Hauz</a>
        <nav class="main-nav">
            <a href="/boarding_hauz/tenant_room.php" class="<?= $tenant_page === 'tenant_room.php' ? 'active' : '' ?>">Room</a>
            <a href="/boarding_hauz/tenant_payments.php" class="<?= $tenant_page === 'tenant_payments.php' ? 'active' : '' ?>">Payments</a>
            <a href="/boarding_hauz/tenant_maintenance.php" class="<?= $tenant_page === 'tenant_maintenance.php' ? 'active' : '' ?>">Maintenance</a>
            <a href="/boarding_hauz/tenant_messages.php" class="<?= $tenant_page === 'tenant_messages.php' ? 'active' : '' ?>">
                Messages
                <?php if ($tenant_unread_count > 0): ?>
                    <span style="display:inline-block; background-color:#135E54; color:#FFFFFF; font-size:0.7rem; font-weight:700; min-width:16px; padding:1px 5px; border-radius:999px; margin-left:4px; vertical-align:middle;"><?= $tenant_unread_count ?></span>
                <?php endif; ?>
            </a>
            <a href="/boarding_hauz/tenant_profile.php" class="<?= $tenant_page === 'tenant_profile.php' ? 'active' : '' ?>">Profile</a>
        </nav>
        <div class="header-actions tenant-header-actions">
            <a href="/boarding_hauz/tenant_dashboard.php" class="btn btn-outline">Dashboard</a>
            <a href="/boarding_hauz/logout.php" class="btn btn-primary">Logout</a>
        </div>
    </div>
</header>
<main class="rent-page">
<header class="rent-page-header">
    <span class="rent-page-badge"><span class="material-symbols-outlined" aria-hidden="true">account_balance</span>Secure Payment Gateway</span>
    <h1>Pay Monthly Rent</h1>
    <p>Choose your payment method and upload your transaction proof.</p>
</header>
<?php if ($error !== ''): ?>
<div class="rent-alert" role="alert"><?= e($error) ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
<div class="rent-alert success" role="status"><?= e($success) ?></div>
<?php endif; ?>
<?php if (!$tenant): ?>
<div class="rent-alert" role="status">Your tenant record could not be found. Please contact management before making a payment.</div>
<?php elseif (empty($tenant['room_id'])): ?>
<div class="rent-alert" role="status">No room is currently assigned to your tenant record, so rent payment submission is unavailable.</div>
<?php endif; ?>
<?php
$dueDays = $currentDue ? (int) (new DateTimeImmutable('today'))->diff($currentDue['due_date'])->format('%r%a') : null;
$dueLabel = $dueDays === null ? 'Due date unavailable' : ($dueDays < 0 ? 'Overdue by ' . abs($dueDays) . ' days' : ($dueDays === 0 ? 'Due today' : 'Due in ' . $dueDays . ' ' . ($dueDays === 1 ? 'day' : 'days')));
$bankLabel = $paymentChannelDetails['Bank Transfer']['label'];
$paymentMethodLabels['Bank Transfer'] = $bankLabel !== 'Bank transfer' ? 'Bank Transfer (' . $bankLabel . ')' : 'Bank Transfer';
?>
<div class="rent-grid">
    <section class="rent-card" aria-labelledby="payment-method-heading">
        <div class="rent-card-heading">
            <div class="rent-card-title">
                <span class="rent-step-number">1</span>
                <h2 id="payment-method-heading">Select Payment Method</h2>
            </div>
            <span class="rent-step-caption">Step 1 of 2</span>
        </div>
        <div class="rent-amount-panel">
            <div>
                <span class="rent-amount-caption">Amount due (<?= e($currentDue ? $currentDue['due_date']->format('F Y') : date('F Y')) ?>)</span>
                <strong class="rent-amount-value"><?= $tenant && $tenant['room_id'] !== null && $currentDue ? '₱' . number_format((float) $currentDue['amount_due'], 2) : '—' ?></strong>
            </div>
            <span class="rent-due-chip"><span class="material-symbols-outlined" aria-hidden="true">schedule</span> <?= e($dueLabel) ?></span>
        </div>
        <div class="rent-method-tabs" role="group" aria-label="Choose a payment method">
            <?php foreach ($paymentMethods as $method): ?>
            <button class="rent-method-tab" type="button" data-payment-method="<?= e($method) ?>" aria-pressed="<?= $method === 'GCash' ? 'true' : 'false' ?>">
                <span class="material-symbols-outlined" aria-hidden="true"><?= $method === 'Cash' ? 'payments' : ($method === 'Bank Transfer' ? 'account_balance' : 'smartphone') ?></span>
                <?= e($method === 'Bank Transfer' ? 'Bank' : $method) ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($paymentMethods as $method): ?>
        <?php $channel = $paymentChannelDetails[$method]; ?>
        <div class="rent-channel-panel" data-channel-panel="<?= e($method) ?>" <?= $method !== 'GCash' ? 'hidden' : '' ?>>
            <div class="rent-qr-placeholder">
                <div>
                    <span class="material-symbols-outlined" aria-hidden="true"><?= $method === 'Cash' ? 'storefront' : 'qr_code_2' ?></span>
                    <?= $method === 'Cash' ? 'Arrange payment directly with your property manager.' : 'QR code is not available. Use the account details provided by your landlord.' ?>
                </div>
            </div>
            <div class="rent-channel-details">
                <p class="rent-channel-label"><span class="material-symbols-outlined" aria-hidden="true"><?= $method === 'Cash' ? 'payments' : ($method === 'Bank Transfer' ? 'account_balance' : 'verified_user') ?></span><?= e($channel['label']) ?></p>
                <?php if ($method === 'Cash'): ?>
                <div class="rent-detail-row"><span>Payment</span><strong>Pay your landlord</strong></div>
                <p class="rent-detail-note">Submit your payment receipt or acknowledgment after paying.</p>
                <?php elseif ($channel['account_name'] !== '' && $channel['account_number'] !== ''): ?>
                <div class="rent-detail-row"><span>Account name</span><strong><?= e($channel['account_name']) ?></strong></div>
                <div class="rent-detail-row"><span>Account number</span><strong><?= e($channel['account_number']) ?></strong></div>
                <div class="rent-detail-row"><span>Amount</span><strong><?= $currentDue ? '₱' . number_format((float) $currentDue['amount_due'], 2) : '—' ?></strong></div>
                <?php else: ?>
                <div class="rent-detail-row"><span>Account</span><strong>Not provided</strong></div>
                <p class="rent-detail-note">Your landlord has not added these payment details yet. Contact them before transferring funds.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </section>

    <section class="rent-card" aria-labelledby="payment-details-heading">
        <div class="rent-card-heading">
            <div class="rent-card-title">
                <span class="rent-step-number">2</span>
                <h2 id="payment-details-heading">Submit Payment Details</h2>
            </div>
            <span class="rent-step-caption">Step 2 of 2</span>
        </div>
        <form class="rent-form" action="tenant_payments.php#payment-submission" enctype="multipart/form-data" method="post" id="payment-submission">
            <input name="csrf_token" type="hidden" value="<?= e($_SESSION['tenant_payments_csrf']) ?>"/>
            <div class="rent-field">
                <label for="payment-method">Payment method used</label>
                <select id="payment-method" name="payment_method" required>
                    <?php foreach ($paymentMethods as $method): ?>
                    <option value="<?= e($method) ?>" <?= $method === 'GCash' ? 'selected' : '' ?>><?= e($paymentMethodLabels[$method]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rent-field">
                <label for="reference-number">Reference / transaction number</label>
                <input id="reference-number" maxlength="100" name="reference_number" placeholder="e.g. 1049289201" required type="text" value="<?= e(is_string($_POST['reference_number'] ?? null) ? $_POST['reference_number'] : '') ?>" autocomplete="off"/>
            </div>
            <div>
                <span class="rent-file-heading">Proof of payment screenshot</span>
                <label class="rent-file-label" for="proof-file">
                    <span>
                        <span class="material-symbols-outlined" aria-hidden="true">cloud_upload</span>
                        <strong id="proof-file-name">Click to upload screenshot</strong>
                        <small>JPG, PNG, WebP or PDF (max. 10 MB)</small>
                    </span>
                </label>
                <input class="rent-file-input" id="proof-file" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" name="proof_file" required type="file"/>
            </div>
            <button class="rent-submit" <?= !$tenant || empty($tenant['room_id']) || !$proofFieldsAvailable || !$currentDue || (float) $currentDue['amount_due'] <= 0 || $currentDue['has_pending_submission'] ? 'disabled' : '' ?> type="submit">
                <span class="material-symbols-outlined" aria-hidden="true">send</span>Submit Payment
            </button>
            <p class="rent-detail-note">Your submission will remain pending until your landlord verifies it. Accepted files: JPG, PNG, WebP, or PDF, up to 10 MB.</p>
        </form>
    </section>
</div>
<section class="rent-history glass-card rounded-2xl p-6 md:p-8 border border-white/80 space-y-6"><!-- 4. TRANSACTION HISTORY: Glass Card Data Table -->
<section class="glass-card rounded-2xl p-6 md:p-8 border border-white/80 space-y-6">
<!-- Table Filter and Header Actions -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
<div>
<h2 class="text-headline-sm font-headline-sm text-[#02353C] font-bold">Transaction History</h2>
<p class="text-label-md font-label-md text-outline">Payments and receipts linked to your tenant record.</p>
</div>
<!-- Filter controls -->
<div class="flex items-center gap-2.5">
<div class="relative">
<span class="absolute inset-y-0 left-0 pl-3 flex items-center text-outline pointer-events-none">
<span class="material-symbols-outlined text-[18px]" data-icon="search">search</span>
</span>
<form action="tenant_payments.php" method="get">
<input class="pl-9 pr-3 py-2 text-body-sm font-body-sm rounded-xl border border-outline-variant/60 bg-white/90 focus:ring-1 focus:ring-[#2EAF7D] focus:border-[#2EAF7D] w-48 sm:w-56" name="reference" placeholder="Search reference #..." type="search" value="<?= e($searchReference) ?>"/>
<button class="sr-only" type="submit">Search payments</button>
</form>
</div>
</div>
</div>
<!-- Responsive Data Table -->
<div class="overflow-x-auto rounded-xl border border-outline-variant/40">
<table class="w-full text-left border-collapse">
<thead>
<tr class="bg-surface-container-low/70 text-outline text-label-sm font-label-sm uppercase tracking-wider border-b border-outline-variant/40">
<th class="py-3 px-4" scope="col">Date</th>
<th class="py-3 px-4" scope="col">Description</th>
<th class="py-3 px-4" scope="col">Amount</th>
<th class="py-3 px-4" scope="col">Channel / Method</th>
<th class="py-3 px-4" scope="col">Reference No.</th>
<th class="py-3 px-4" scope="col">Status</th>
<th class="py-3 px-4 text-right" scope="col">Receipt / Proof</th>
</tr>
</thead>
                <tbody class="divide-y divide-outline-variant/30 text-body-sm font-body-sm text-[#02353C]">
                <?php foreach ($payments as $payment): ?>
                <?php [$statusLabel, $statusClass] = paymentStatusPresentation((string) $payment['status']); ?>
                <tr class="hover:bg-white/60 transition-colors">
                <td class="py-3.5 px-4 font-medium whitespace-nowrap"><?= e(date('M j, Y', strtotime((string) $payment['payment_date']))) ?></td>
                <td class="py-3.5 px-4">
                <div class="font-semibold text-[#02353C]">Room rent payment</div>
                <div class="text-[12px] text-outline"><?= $tenant && $tenant['room_number'] !== null ? 'Room ' . e($tenant['room_number']) : 'Tenant payment' ?></div>
                </td>
                <td class="py-3.5 px-4 font-bold text-[#02353C] whitespace-nowrap">₱<?= number_format((float) $payment['amount_paid'], 2) ?></td>
                <td class="py-3.5 px-4 whitespace-nowrap"><?= e($payment['payment_method']) ?></td>
                <td class="py-3.5 px-4 font-mono text-label-sm font-label-sm text-outline whitespace-nowrap"><?= e($payment['reference_number'] ?: '—') ?></td>
                <td class="py-3.5 px-4 whitespace-nowrap"><span class="inline-flex items-center px-2.5 py-1 rounded-full text-label-sm font-label-sm font-semibold border <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                <?php if (!empty($payment['proof_path'])): ?>
                <a class="inline-flex items-center gap-1 text-[#2EAF7D] font-label-md hover:underline" href="tenant-payment-proof.php?id=<?= (int) $payment['id'] ?>" rel="noopener" target="_blank"><span class="material-symbols-outlined text-[16px]" data-icon="visibility">visibility</span>View proof</a>
                <?php else: ?>
                <span class="text-outline">—</span>
                <?php endif; ?>
                </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
</table>
</div>
<!-- Pagination and Record Summary -->
<div class="flex flex-col sm:flex-row items-center justify-between gap-4 pt-2">
<span class="text-body-sm font-body-sm text-outline"><?= count($payments) ?> payment record<?= count($payments) === 1 ? '' : 's' ?><?= $searchReference !== '' ? ' matching “' . e($searchReference) . '”' : '' ?><?= count($payments) === 100 ? ' (latest 100 shown)' : '' ?></span>
</div>
<?php if ($payments === []): ?>
<div class="py-8 text-center max-w-sm mx-auto space-y-3">
<div class="w-14 h-14 mx-auto rounded-full bg-[#f0f3ff] text-outline flex items-center justify-center">
<span class="material-symbols-outlined text-[28px]" data-icon="receipt_long">receipt_long</span>
</div>
<h4 class="text-headline-sm font-headline-sm text-[#02353C]">No Payment Records Found</h4>
<p class="text-body-sm font-body-sm text-outline">
            <?= $searchReference !== '' ? 'No payments match that reference. Try another search or clear the filter.' : 'No payment records have been submitted yet. Submit your first receipt above.' ?>
          </p>
<?php if ($searchReference !== ''): ?><a class="inline-block px-4 py-2 rounded-lg border border-outline-variant text-[#02353C] font-label-md text-label-md hover:bg-white" href="tenant_payments.php">Clear search</a><?php endif; ?>
</div>
<?php endif; ?>
</section>
</main>
<script>
(() => {
    const methodSelect = document.querySelector('#payment-method');
    const methodTabs = Array.from(document.querySelectorAll('[data-payment-method]'));
    const channelPanels = Array.from(document.querySelectorAll('[data-channel-panel]'));
    const fileInput = document.querySelector('#proof-file');
    const fileName = document.querySelector('#proof-file-name');

    const selectMethod = (method) => {
        if (!methodSelect || !methodTabs.length || !channelPanels.length) {
            return;
        }
        methodSelect.value = method;
        methodTabs.forEach((tab) => {
            tab.setAttribute('aria-pressed', tab.dataset.paymentMethod === method ? 'true' : 'false');
        });
        channelPanels.forEach((panel) => {
            panel.hidden = panel.dataset.channelPanel !== method;
        });
    };

    methodTabs.forEach((tab) => {
        tab.addEventListener('click', () => selectMethod(tab.dataset.paymentMethod));
    });
    methodSelect?.addEventListener('change', () => selectMethod(methodSelect.value));
    fileInput?.addEventListener('change', () => {
        if (fileName) {
            fileName.textContent = fileInput.files?.[0]?.name || 'Click to upload screenshot';
        }
    });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>