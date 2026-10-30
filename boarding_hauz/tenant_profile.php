<?php

require_once __DIR__ . '/auth.php';
requireRole('tenant');

$userId = (int) $_SESSION['user_id'];

$successMessage = '';
$errorMessage = '';
$passwordError = '';

/* =========================================================
   GET LOGGED-IN TENANT INFORMATION
   ========================================================= */

$stmt = $conn->prepare("
    SELECT
        u.id AS user_id,
        u.username,
        u.email,
        u.role,
        u.status AS account_status,
        u.created_at,
        t.id AS tenant_id,
        t.full_name,
        t.contact_number,
        t.room_id
    FROM users u
    LEFT JOIN tenants t ON t.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
");

$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();
$tenant = $result->fetch_assoc();

if (!$tenant) {
    die('Tenant account information could not be found.');
}


/* =========================================================
   UPDATE PERSONAL INFORMATION
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {

    $fullName = trim($_POST['full_name'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($fullName === '') {

        $errorMessage = 'Full name is required.';

    } elseif ($email === '') {

        $errorMessage = 'Email address is required.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $errorMessage = 'Please enter a valid email address.';

    } else {

        /* Check if another account is already using the email */

        $emailCheck = $conn->prepare("
            SELECT id
            FROM users
            WHERE email = ?
              AND id != ?
            LIMIT 1
        ");

        $emailCheck->bind_param('si', $email, $userId);
        $emailCheck->execute();

        $emailResult = $emailCheck->get_result();

        if ($emailResult->num_rows > 0) {

            $errorMessage =
                'That email address is already being used by another account.';

        } else {

            /* Update users table */

            $updateUser = $conn->prepare("
                UPDATE users
                SET email = ?
                WHERE id = ?
            ");

            $updateUser->bind_param('si', $email, $userId);
            $updateUser->execute();


            /* Update tenant information */

            if (!empty($tenant['tenant_id'])) {

                $updateTenant = $conn->prepare("
                    UPDATE tenants
                    SET full_name = ?,
                        contact_number = ?
                    WHERE user_id = ?
                ");

                $updateTenant->bind_param(
                    'ssi',
                    $fullName,
                    $contactNumber,
                    $userId
                );

                $updateTenant->execute();

            } else {

                /* Create tenant record if one does not exist */

                $createTenant = $conn->prepare("
                    INSERT INTO tenants (
                        user_id,
                        full_name,
                        contact_number
                    )
                    VALUES (?, ?, ?)
                ");

                $createTenant->bind_param(
                    'iss',
                    $userId,
                    $fullName,
                    $contactNumber
                );

                $createTenant->execute();
            }


            $successMessage =
                'Your profile has been updated successfully.';


            /* Refresh tenant data */

            $stmt = $conn->prepare("
                SELECT
                    u.id AS user_id,
                    u.username,
                    u.email,
                    u.role,
                    u.status AS account_status,
                    u.created_at,
                    t.id AS tenant_id,
                    t.full_name,
                    t.contact_number,
                    t.room_id
                FROM users u
                LEFT JOIN tenants t ON t.user_id = u.id
                WHERE u.id = ?
                LIMIT 1
            ");

            $stmt->bind_param('i', $userId);
            $stmt->execute();

            $tenant = $stmt->get_result()->fetch_assoc();
        }
    }
}


/* =========================================================
   CHANGE PASSWORD
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (
        $currentPassword === '' ||
        $newPassword === '' ||
        $confirmPassword === ''
    ) {

        $passwordError =
            'Please complete all password fields.';

    } elseif (
        !password_verify(
            $currentPassword,
            getUserPassword($conn, $userId)
        )
    ) {

        $passwordError =
            'Your current password is incorrect.';

    } elseif (strlen($newPassword) < 8) {

        $passwordError =
            'New password must be at least 8 characters long.';

    } elseif ($newPassword !== $confirmPassword) {

        $passwordError =
            'New password and confirmation password do not match.';

    } elseif ($currentPassword === $newPassword) {

        $passwordError =
            'Your new password must be different from your current password.';

    } else {

        $hashedPassword = password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );

        $updatePassword = $conn->prepare("
            UPDATE users
            SET password = ?
            WHERE id = ?
        ");

        $updatePassword->bind_param(
            'si',
            $hashedPassword,
            $userId
        );

        if ($updatePassword->execute()) {

            $successMessage =
                'Your password has been updated successfully.';

        } else {

            $passwordError =
                'Unable to update your password. Please try again.';
        }
    }
}


/* =========================================================
   GET USER PASSWORD
   ========================================================= */

function getUserPassword($conn, $userId)
{
    $stmt = $conn->prepare("
        SELECT password
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    return $user['password'] ?? '';
}


/* =========================================================
   DISPLAY VALUES
   ========================================================= */

$fullName = $tenant['full_name'] ?? $tenant['username'] ?? 'Tenant';
$email = $tenant['email'] ?? '';
$contactNumber = $tenant['contact_number'] ?? '';
$username = $tenant['username'] ?? '';
$role = $tenant['role'] ?? 'tenant';

$accountStatus = $tenant['account_status'] ?? 'Active';

$displayStatus = ucfirst(strtolower($accountStatus));

$memberSince = 'Not available';

if (!empty($tenant['created_at'])) {

    $memberSince = date(
        'F j, Y',
        strtotime($tenant['created_at'])
    );
}


/* =========================================================
   PAGE
   ========================================================= */

include __DIR__ . '/includes/tenant_header.php';
?>

<style>

/* =========================================================
   PROFILE PAGE
   ========================================================= */

.profile-page {
    min-height: calc(100vh - 80px);
    background: #edf9fc;
    padding: 40px 24px 60px;
}

.profile-container {
    max-width: 1100px;
    margin: 0 auto;
}


/* =========================================================
   PAGE HEADER
   ========================================================= */

.profile-page-header {
    background: #0d5b50;
    border-radius: 14px;
    padding: 28px 30px;
    margin-bottom: 28px;
    padding: 20px 22px;
    border: 1px solid #236b67;
    background: #0d5b50;
    border-radius: 16px;
}

.profile-page-header h1 {
    margin: 0 0 7px;
    color: #ffffff;
    font-family: 'Playfair Display', serif;
    font-size: 36px;
    font-weight: 700;
}

.profile-page-header p {
    margin: 0;
    color: #dff3f7;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
}


/* =========================================================
   ALERTS
   ========================================================= */

.profile-alert {
    padding: 13px 16px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
}

.profile-alert.success {
    background: #dff3f7;
    color: #236b67;
    border: 1px solid #b9e7df;
}

.profile-alert.error {
    background: #fff0f0;
    color: #ba1a1a;
    border: 1px solid #efcaca;
}


/* =========================================================
   PROFILE SUMMARY
   ========================================================= */

.profile-summary {
    background: #ffffff;
    border: 1px solid #d7e6ea;
    border-radius: 16px;
    padding: 28px;
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 24px;
    box-shadow: 0 4px 16px rgba(13, 28, 30, 0.04);
}

.profile-avatar {
    width: 78px;
    height: 78px;
    min-width: 78px;
    border-radius: 50%;
    background: #dff3f7;
    color: #22b486;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    font-weight: 700;
    font-family: 'Inter', sans-serif;
}

.profile-summary-info h2 {
    margin: 0 0 6px;
    color: #0d1c1e;
    font-family: 'Playfair Display', serif;
    font-size: 24px;
}

.profile-summary-info p {
    margin: 4px 0;
    color: #236b67;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
}

.profile-account-type {
    display: inline-block;
    margin-top: 7px;
    padding: 5px 10px;
    border-radius: 20px;
    background: #edf9fc;
    color: #236b67;
    font-size: 12px;
    font-weight: 600;
}


/* =========================================================
   MAIN GRID
   Personal Information + Security = LEFT
   Account Information = RIGHT
   ========================================================= */

.profile-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.5fr) minmax(280px, 1fr);
    gap: 24px;
    align-items: start;
}


/* =========================================================
   CARDS
   ========================================================= */

.profile-card {
    background: #ffffff;
    border: 1px solid #d7e6ea;
    border-radius: 16px;
    padding: 26px;
    margin-bottom: 24px;
    box-shadow: 0 4px 16px rgba(13, 28, 30, 0.04);
}

.profile-card:last-child {
    margin-bottom: 0;
}

.profile-card-header {
    margin-bottom: 22px;
}

.profile-card-header h2 {
    margin: 0 0 5px;
    color: #0d1c1e;
    font-family: 'Playfair Display', serif;
    font-size: 21px;
}

.profile-card-header p {
    margin: 0;
    color: #6a7c7f;
    font-family: 'Inter', sans-serif;
    font-size: 13px;
}


/* =========================================================
   PERSONAL INFORMATION
   ========================================================= */

.profile-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.profile-field {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.profile-field label {
    color: #236b67;
    font-family: 'Inter', sans-serif;
    font-size: 12px;
    font-weight: 600;
}

.profile-value {
    color: #0d1c1e;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    min-height: 20px;
}

.profile-input {
    width: 100%;
    box-sizing: border-box;
    padding: 11px 12px;
    border: 1px solid #d7e6ea;
    border-radius: 9px;
    background: #ffffff;
    color: #0d1c1e;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    outline: none;
}

.profile-input:focus {
    border-color: #22b486;
    box-shadow: 0 0 0 3px rgba(34, 180, 134, 0.10);
}

.read-only {
    color: #5d7073;
}


/* =========================================================
   BUTTONS
   ========================================================= */

.profile-actions {
    margin-top: 24px;
    display: flex;
    gap: 10px;
}

.profile-btn {
    border: none;
    border-radius: 9px;
    padding: 11px 17px;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.profile-btn-primary {
    background: #22b486;
    color: #ffffff;
}

.profile-btn-primary:hover {
    background: #1aa27a;
}

.profile-btn-secondary {
    background: #edf9fc;
    color: #236b67;
    border: 1px solid #d7e6ea;
}

.profile-btn-secondary:hover {
    background: #dff3f7;
}


/* =========================================================
   ACCOUNT INFORMATION
   ========================================================= */

.account-info-list {
    display: flex;
    flex-direction: column;
}

.account-info-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    padding: 16px 0;
    border-bottom: 1px solid #edf1f2;
}

.account-info-item:first-child {
    padding-top: 0;
}

.account-info-item:last-child {
    padding-bottom: 0;
    border-bottom: none;
}

.account-info-label {
    color: #6a7c7f;
    font-family: 'Inter', sans-serif;
    font-size: 13px;
}

.account-info-value {
    color: #0d1c1e;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    font-weight: 600;
    text-align: right;
}


/* =========================================================
   STATUS
   ========================================================= */

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #dff3f7;
    color: #236b67;
    border-radius: 20px;
    padding: 5px 10px;
    font-size: 12px;
    font-weight: 600;
}

.status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #22b486;
}


/* =========================================================
   SECURITY
   ========================================================= */

.security-password {
    padding: 13px 14px;
    background: #edf9fc;
    border: 1px solid #d7e6ea;
    border-radius: 9px;
    color: #0d1c1e;
    font-family: 'Inter', sans-serif;
    font-size: 15px;
    letter-spacing: 2px;
    margin-bottom: 15px;
}

.password-form {
    display: none;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #d7e6ea;
}

.password-form.show {
    display: block;
}

.password-fields {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.password-field {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.password-field label {
    color: #236b67;
    font-family: 'Inter', sans-serif;
    font-size: 12px;
    font-weight: 600;
}


/* =========================================================
   PASSWORD INPUT WITH EYE BUTTON
   ========================================================= */

.password-input-wrapper {
    position: relative;
    width: 100%;
}

.password-field input {
    width: 100%;
    box-sizing: border-box;
    padding: 11px 45px 11px 12px;
    border: 1px solid #d7e6ea;
    border-radius: 9px;
    background: #ffffff;
    color: #0d1c1e;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    outline: none;
}

.password-field input:focus {
    border-color: #22b486;
    box-shadow: 0 0 0 3px rgba(34, 180, 134, 0.10);
}


/* Eye Button */

.password-toggle {
    position: absolute;
    top: 50%;
    right: 8px;
    transform: translateY(-50%);

    width: 32px;
    height: 32px;

    border: none;
    background: transparent;

    display: flex;
    align-items: center;
    justify-content: center;

    padding: 0;
    margin: 0;

    cursor: pointer;

    color: #236b67;
}

.password-toggle:hover {
    color: #22b486;
}

.password-toggle:focus {
    outline: none;
    color: #22b486;
}

.password-toggle svg {
    width: 18px;
    height: 18px;
    pointer-events: none;
}


/* =========================================================
   PASSWORD NOTE
   ========================================================= */

.password-note {
    margin-top: 8px;
    color: #6a7c7f;
    font-family: 'Inter', sans-serif;
    font-size: 12px;
}


/* =========================================================
   MOBILE
   ========================================================= */

@media (max-width: 800px) {

    .profile-page {
        padding: 30px 16px 45px;
    }

    .profile-page-header h1 {
        font-size: 30px;
    }

    .profile-grid {
        grid-template-columns: 1fr;
    }

    .profile-summary {
        padding: 22px;
    }
}


@media (max-width: 600px) {

    .profile-info-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }

    .profile-summary {
        align-items: flex-start;
    }

    .profile-avatar {
        width: 64px;
        height: 64px;
        min-width: 64px;
        font-size: 26px;
    }

    .profile-summary-info h2 {
        font-size: 21px;
    }

    .profile-card {
        padding: 21px;
    }

    .account-info-item {
        align-items: flex-start;
        flex-direction: column;
        gap: 5px;
    }

    .account-info-value {
        text-align: left;
    }

    .profile-actions {
        flex-direction: column;
    }

    .profile-btn {
        width: 100%;
    }
}

</style>


<main class="profile-page">

    <div class="profile-container">


        <!-- =====================================================
             PAGE HEADER
             ===================================================== -->

        <div class="profile-page-header">

            <h1>My Profile</h1>

            <p>
                Manage your account information.
            </p>

        </div>


        <!-- =====================================================
             ALERTS
             ===================================================== -->

        <?php if ($successMessage): ?>

            <div class="profile-alert success">
                <?= e($successMessage) ?>
            </div>

        <?php endif; ?>


        <?php if ($errorMessage): ?>

            <div class="profile-alert error">
                <?= e($errorMessage) ?>
            </div>

        <?php endif; ?>


        <?php if ($passwordError): ?>

            <div class="profile-alert error">
                <?= e($passwordError) ?>
            </div>

        <?php endif; ?>


        <!-- =====================================================
             PROFILE SUMMARY
             ===================================================== -->

        <section class="profile-summary">

            <div class="profile-avatar">

                <?= e(
                    strtoupper(
                        substr($fullName, 0, 1)
                    )
                ) ?>

            </div>


            <div class="profile-summary-info">

                <h2>
                    <?= e($fullName) ?>
                </h2>

                <p>
                    <?= e(
                        $email ?: 'No email address'
                    ) ?>
                </p>

                <span class="profile-account-type">
                    Tenant Account
                </span>

            </div>

        </section>


        <!-- =====================================================
             MAIN GRID
             ===================================================== -->

        <div class="profile-grid">


            <!-- =================================================
                 LEFT COLUMN
                 ================================================= -->

            <div>


                <!-- =============================================
                     PERSONAL INFORMATION
                     ============================================= -->

                <section class="profile-card">

                    <div class="profile-card-header">

                        <h2>
                            Personal Information
                        </h2>

                        <p>
                            Update the personal information
                            associated with your tenant account.
                        </p>

                    </div>


                    <form method="POST">

                        <div class="profile-info-grid">


                            <!-- Full Name -->

                            <div class="profile-field">

                                <label for="full_name">
                                    Full Name
                                </label>

                                <input
                                    type="text"
                                    id="full_name"
                                    name="full_name"
                                    class="profile-input"
                                    value="<?= e($fullName) ?>"
                                    required
                                >

                            </div>


                            <!-- Email -->

                            <div class="profile-field">

                                <label for="email">
                                    Email Address
                                </label>

                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    class="profile-input"
                                    value="<?= e($email) ?>"
                                    required
                                >

                            </div>


                            <!-- Contact -->

                            <div class="profile-field">

                                <label for="contact_number">
                                    Contact Number
                                </label>

                                <input
                                    type="text"
                                    id="contact_number"
                                    name="contact_number"
                                    class="profile-input"
                                    value="<?= e($contactNumber) ?>"
                                    placeholder="Enter contact number"
                                >

                            </div>


                            <!-- Username -->

                            <div class="profile-field">

                                <label>
                                    Username
                                </label>

                                <div class="profile-value read-only">
                                    <?= e($username) ?>
                                </div>

                            </div>

                        </div>


                        <div class="profile-actions">

                            <button
                                type="submit"
                                name="update_profile"
                                class="profile-btn profile-btn-primary"
                            >
                                Save Changes
                            </button>

                        </div>

                    </form>

                </section>


                <!-- =============================================
                     SECURITY
                     ============================================= -->

                <section class="profile-card">

                    <div class="profile-card-header">

                        <h2>
                            Security
                        </h2>

                        <p>
                            Keep your account secure by updating
                            your password when needed.
                        </p>

                    </div>


                    <!-- Current masked password display -->

                    <div class="security-password">
                        ••••••••••••
                    </div>


                    <button
                        type="button"
                        class="profile-btn profile-btn-secondary"
                        onclick="togglePasswordForm()"
                        id="passwordToggleButton"
                    >
                        Change Password
                    </button>


                    <!-- =========================================
                         PASSWORD FORM
                         ========================================= -->

                    <div
                        class="password-form"
                        id="passwordForm"
                    >

                        <form method="POST">

                            <div class="password-fields">


                                <!-- Current Password -->

                                <div class="password-field">

                                    <label for="current_password">
                                        Current Password
                                    </label>

                                    <div class="password-input-wrapper">

                                        <input
                                            type="password"
                                            id="current_password"
                                            name="current_password"
                                            autocomplete="current-password"
                                            required
                                        >

                                        <button
                                            type="button"
                                            class="password-toggle"
                                            data-target="current_password"
                                            aria-label="Show current password"
                                            title="Show password"
                                        >

                                            <!-- Eye icon -->

                                            <svg
                                                class="eye-icon"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                            >
                                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path>
                                                <circle cx="12" cy="12" r="3"></circle>
                                            </svg>

                                        </button>

                                    </div>

                                </div>


                                <!-- New Password -->

                                <div class="password-field">

                                    <label for="new_password">
                                        New Password
                                    </label>

                                    <div class="password-input-wrapper">

                                        <input
                                            type="password"
                                            id="new_password"
                                            name="new_password"
                                            autocomplete="new-password"
                                            minlength="8"
                                            required
                                        >

                                        <button
                                            type="button"
                                            class="password-toggle"
                                            data-target="new_password"
                                            aria-label="Show new password"
                                            title="Show password"
                                        >

                                            <svg
                                                class="eye-icon"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                            >
                                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path>
                                                <circle cx="12" cy="12" r="3"></circle>
                                            </svg>

                                        </button>

                                    </div>

                                </div>


                                <!-- Confirm Password -->

                                <div class="password-field">

                                    <label for="confirm_password">
                                        Confirm New Password
                                    </label>

                                    <div class="password-input-wrapper">

                                        <input
                                            type="password"
                                            id="confirm_password"
                                            name="confirm_password"
                                            autocomplete="new-password"
                                            minlength="8"
                                            required
                                        >

                                        <button
                                            type="button"
                                            class="password-toggle"
                                            data-target="confirm_password"
                                            aria-label="Show confirm password"
                                            title="Show password"
                                        >

                                            <svg
                                                class="eye-icon"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                            >
                                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path>
                                                <circle cx="12" cy="12" r="3"></circle>
                                            </svg>

                                        </button>

                                    </div>

                                </div>

                            </div>


                            <div class="password-note">

                                Use at least 8 characters for your new password.

                            </div>


                            <div class="profile-actions">

                                <button
                                    type="submit"
                                    name="change_password"
                                    class="profile-btn profile-btn-primary"
                                >
                                    Update Password
                                </button>

                            </div>

                        </form>

                    </div>

                </section>

            </div>


            <!-- =================================================
                 RIGHT COLUMN
                 ACCOUNT INFORMATION
                 ================================================= -->

            <div>

                <section class="profile-card">

                    <div class="profile-card-header">

                        <h2>
                            Account Information
                        </h2>

                        <p>
                            Information managed by the system.
                        </p>

                    </div>


                    <div class="account-info-list">


                        <!-- Account Status -->

                        <div class="account-info-item">

                            <div class="account-info-label">
                                Account Status
                            </div>

                            <div class="account-info-value">

                                <span class="status-badge">

                                    <span class="status-dot"></span>

                                    <?= e($displayStatus) ?>

                                </span>

                            </div>

                        </div>


                        <!-- Member Since -->

                        <div class="account-info-item">

                            <div class="account-info-label">
                                Member Since
                            </div>

                            <div class="account-info-value">
                                <?= e($memberSince) ?>
                            </div>

                        </div>


                        <!-- Account Type -->

                        <div class="account-info-item">

                            <div class="account-info-label">
                                Account Type
                            </div>

                            <div class="account-info-value">
                                Tenant
                            </div>

                        </div>


                        <!-- Username -->

                        <div class="account-info-item">

                            <div class="account-info-label">
                                Username
                            </div>

                            <div class="account-info-value">
                                <?= e($username) ?>
                            </div>

                        </div>

                    </div>

                </section>

            </div>

        </div>

    </div>

</main>


<script>

/* =========================================================
   CHANGE PASSWORD FORM
   ========================================================= */

function togglePasswordForm() {

    const form =
        document.getElementById('passwordForm');

    const button =
        document.getElementById('passwordToggleButton');

    if (form.classList.contains('show')) {

        form.classList.remove('show');

        button.textContent = 'Change Password';

    } else {

        form.classList.add('show');

        button.textContent = 'Cancel';
    }
}


/* =========================================================
   PASSWORD VISIBILITY TOGGLE
   This is intentionally inside tenant_profile.php
   so it does NOT depend on script.js.
   ========================================================= */

document.addEventListener('DOMContentLoaded', function () {

    const passwordButtons =
        document.querySelectorAll('.password-toggle');


    passwordButtons.forEach(function (button) {

        button.addEventListener('click', function () {

            const targetId =
                button.getAttribute('data-target');

            const passwordInput =
                document.getElementById(targetId);


            if (!passwordInput) {
                return;
            }


            if (passwordInput.type === 'password') {

                /* SHOW PASSWORD */

                passwordInput.type = 'text';

                button.setAttribute(
                    'aria-label',
                    'Hide password'
                );

                button.setAttribute(
                    'title',
                    'Hide password'
                );

                /* Change icon to crossed eye */

                button.innerHTML = `
                    <svg
                        class="eye-icon"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M3 3l18 18"></path>
                        <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"></path>
                        <path d="M9.9 4.2A10.8 10.8 0 0 1 12 4c6.5 0 10 8 10 8a17.4 17.4 0 0 1-3.2 4.4"></path>
                        <path d="M6.6 6.6C3.6 8.5 2 12 2 12s3.5 8 10 8c1.5 0 2.8-.3 4-.8"></path>
                    </svg>
                `;

            } else {

                /* HIDE PASSWORD */

                passwordInput.type = 'password';

                button.setAttribute(
                    'aria-label',
                    'Show password'
                );

                button.setAttribute(
                    'title',
                    'Show password'
                );

                /* Change icon back to normal eye */

                button.innerHTML = `
                    <svg
                        class="eye-icon"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                `;
            }

        });

    });

});

</script>


<?php include __DIR__ . '/includes/footer.php'; ?>