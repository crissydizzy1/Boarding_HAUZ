<?php
require_once __DIR__ . '/auth.php';

$errors = [];
$form = [
    'full_name' => '',
    'contact_number' => '',
    'username' => '',
    'email' => '',
    'branch_name' => '',
    'location' => '',
];

if (empty($_SESSION['landlord_register_csrf'])) {
    $_SESSION['landlord_register_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($form) as $field) {
        $form[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
    }
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    $postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';

    if (!hash_equals($_SESSION['landlord_register_csrf'], $postedToken)) {
        $errors[] = 'Your session could not be verified. Refresh the page and try again.';
    }
    if ($form['full_name'] === '' || mb_strlen($form['full_name']) > 100) {
        $errors[] = 'Enter your full name (up to 100 characters).';
    }
    if (!preg_match('/^[0-9+(). \-]{7,20}$/D', $form['contact_number'])
        || strlen(preg_replace('/\D/', '', $form['contact_number'])) < 7) {
        $errors[] = 'Enter a valid contact number.';
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{3,100}$/D', $form['username'])) {
        $errors[] = 'Username must be 3 to 100 characters using letters, numbers, dots, underscores, or hyphens.';
    }
    $form['email'] = strtolower($form['email']);
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 255) {
        $errors[] = 'Enter a valid email address (up to 255 characters).';
    }
    if ($form['branch_name'] === '' || mb_strlen($form['branch_name']) > 120) {
        $errors[] = 'Enter a property name (up to 120 characters).';
    }
    if ($form['location'] === '' || mb_strlen($form['location']) > 255) {
        $errors[] = 'Enter a property location (up to 255 characters).';
    }
    if (strlen($password) < 12 || strlen($password) > 72) {
        $errors[] = 'Password must be at least 12 characters and no more than 72 bytes.';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        $transactionStarted = false;
        try {
            $check = $conn->prepare('
                SELECT id
                FROM users
                WHERE username = ? OR email = ? OR username = ? OR email = ?
                LIMIT 1
            ');
            $check->bind_param(
                'ssss',
                $form['username'],
                $form['email'],
                $form['email'],
                $form['username']
            );
            $check->execute();
            $duplicate = (bool) $check->get_result()->fetch_assoc();
            $check->close();

            if ($duplicate) {
                $errors[] = 'That username or email is already registered.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                if (!is_string($passwordHash)) {
                    throw new RuntimeException('The password could not be secured. Please try again.');
                }

                $conn->begin_transaction();
                $transactionStarted = true;
                $role = 'super_landlord';
                $status = 'active';
                $stmt = $conn->prepare("
                    INSERT INTO users (username, email, password, role, status, full_name, contact_number)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    'sssssss',
                    $form['username'],
                    $form['email'],
                    $passwordHash,
                    $role,
                    $status,
                    $form['full_name'],
                    $form['contact_number']
                );
                $stmt->execute();
                $landlordId = (int) $conn->insert_id;
                $stmt->close();

                $stmt = $conn->prepare('INSERT INTO branches (landlord_id, branch_name, location) VALUES (?, ?, ?)');
                $stmt->bind_param('iss', $landlordId, $form['branch_name'], $form['location']);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                $transactionStarted = false;
                redirectTo('/boarding_hauz/login.php?landlord_registered=1');
            }
        } catch (mysqli_sql_exception $exception) {
            if ($transactionStarted) {
                $conn->rollback();
            }
            error_log('Public landlord registration failed: ' . $exception->getMessage());
            $errors[] = (int) $exception->getCode() === 1062
                ? 'That username or email is already registered.'
                : 'Registration could not be completed. Please try again.';
        } catch (RuntimeException $exception) {
            if ($transactionStarted) {
                $conn->rollback();
            }
            error_log('Public landlord registration failed: ' . $exception->getMessage());
            $errors[] = $exception->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Landlord Registration | Boarding Hauz</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/boarding_hauz/assets/css/style.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/style.css'); ?>">
    <style>
        body.landlord-register-page { min-height: 100vh; background: #f8faf9; color: #24332f; font-family: 'Inter', sans-serif; }
        .landlord-register-shell { width: min(100% - 32px, 560px); margin: 0 auto; padding: 28px 0 48px; }
        .landlord-register-topbar { margin-bottom: 16px; font-size: 13px; }
        .landlord-register-topbar a, .landlord-register-login a { color: #16866f; font-weight: 700; text-decoration: none; }
        .landlord-register-card { overflow: hidden; border: 1px solid #d7e6ea; border-radius: 14px; background: #fff; box-shadow: 0 18px 45px rgba(32, 66, 54, .1); }
        .landlord-register-heading { padding: 26px 30px 20px; background: #0f5c4d; color: #fff; }
        .landlord-register-heading h1 { margin: 0 0 8px; color: inherit; font: 700 25px/1.2 'Playfair Display', serif; }
        .landlord-register-heading p { margin: 0; color: #d6ebe5; font-size: 13px; line-height: 1.6; }
        .landlord-register-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 15px; padding: 24px 30px; }
        .landlord-register-field label { display: block; margin-bottom: 6px; color: #334641; font-size: 12px; font-weight: 700; }
        .landlord-register-field input { width: 100%; min-height: 43px; padding: 9px 11px; border: 1px solid #d7e6ea; border-radius: 7px; color: #24332f; font: 400 13px 'Inter', sans-serif; }
        .landlord-register-field input:focus { border-color: #22b486; outline: 3px solid rgba(34, 180, 134, .16); }
        .landlord-register-field.full-width, .landlord-register-submit { grid-column: 1 / -1; }
        .landlord-register-submit { min-height: 46px; border: 0; border-radius: 7px; background: #22b486; color: #fff; cursor: pointer; font: 700 13px 'Inter', sans-serif; }
        .landlord-register-submit:hover { background: #1aa27a; }
        .landlord-register-alert { margin: 18px 30px 0; padding: 10px 12px; border-radius: 7px; background: #fff0ed; color: #8a3026; font-size: 12px; line-height: 1.5; }
        .landlord-register-alert div + div { margin-top: 4px; }
        .landlord-register-login { padding: 16px 24px; border-top: 1px solid #edf2f0; color: #65736e; font-size: 12px; text-align: center; }
        @media (max-width: 520px) { .landlord-register-form { grid-template-columns: 1fr; padding: 20px; } .landlord-register-field.full-width, .landlord-register-submit { grid-column: auto; } .landlord-register-heading { padding: 23px 20px 18px; } .landlord-register-alert { margin-right: 20px; margin-left: 20px; } }
    </style>
</head>
<body class="landlord-register-page">
    <main class="landlord-register-shell">
        <div class="landlord-register-topbar"><a href="/boarding_hauz/login.php">← Back to sign in</a></div>
        <section class="landlord-register-card" aria-labelledby="landlord-register-title">
            <header class="landlord-register-heading">
                <h1 id="landlord-register-title">Create a Super Landlord account</h1>
                <p>Register your profile and first property branch. Your account will be active as soon as registration is complete.</p>
            </header>

            <?php if ($errors): ?>
                <div class="landlord-register-alert" role="alert" aria-live="polite">
                    <?php foreach ($errors as $error): ?><div><?= e($error); ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" class="landlord-register-form">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['landlord_register_csrf']); ?>">
                <div class="landlord-register-field">
                    <label for="full_name">Full name</label>
                    <input id="full_name" name="full_name" maxlength="100" autocomplete="name" value="<?= e($form['full_name']); ?>" required>
                </div>
                <div class="landlord-register-field">
                    <label for="contact_number">Phone number</label>
                    <input id="contact_number" name="contact_number" type="tel" maxlength="20" autocomplete="tel" value="<?= e($form['contact_number']); ?>" required>
                </div>
                <div class="landlord-register-field">
                    <label for="username">Username</label>
                    <input id="username" name="username" maxlength="100" autocomplete="username" value="<?= e($form['username']); ?>" required>
                </div>
                <div class="landlord-register-field">
                    <label for="email">Email address</label>
                    <input id="email" name="email" type="email" maxlength="255" autocomplete="email" value="<?= e($form['email']); ?>" required>
                </div>
                <div class="landlord-register-field">
                    <label for="branch_name">Property / branch name</label>
                    <input id="branch_name" name="branch_name" maxlength="120" value="<?= e($form['branch_name']); ?>" required>
                </div>
                <div class="landlord-register-field">
                    <label for="location">Property location</label>
                    <input id="location" name="location" maxlength="255" autocomplete="street-address" value="<?= e($form['location']); ?>" required>
                </div>
                <div class="landlord-register-field">
                    <label for="password">Password (12+ characters, up to 72 bytes)</label>
                    <input id="password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
                </div>
                <div class="landlord-register-field">
                    <label for="confirm_password">Confirm password</label>
                    <input id="confirm_password" name="confirm_password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
                </div>
                <button class="landlord-register-submit" type="submit">CREATE SUPER LANDLORD ACCOUNT</button>
            </form>

            <div class="landlord-register-login">Already have an account? <a href="/boarding_hauz/login.php">Sign in</a></div>
        </section>
    </main>
</body>
</html>
