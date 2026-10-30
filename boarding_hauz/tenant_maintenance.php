<?php

require_once __DIR__ . '/auth.php';
requireRole('tenant');

$userId = (int) $_SESSION['user_id'];

$successMessage = '';
$errorMessage = '';
$selectedRequest = null;
if (empty($_SESSION['tenant_maintenance_csrf'])) {
    $_SESSION['tenant_maintenance_csrf'] = bin2hex(random_bytes(32));
}


/* =========================================================
   GET TENANT INFORMATION
   ========================================================= */

$stmt = $conn->prepare("
    SELECT
        t.id,
        t.branch_id,
        t.room_id,
        t.full_name
    FROM tenants t
    WHERE t.user_id = ?
    LIMIT 1
");

$stmt->bind_param('i', $userId);
$stmt->execute();

$tenant = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tenant) {
    die('Tenant information could not be found.');
}

$tenantId = (int) $tenant['id'];
$tenantBranchId = $tenant['branch_id'] === null ? null : (int) $tenant['branch_id'];
$roomId = !empty($tenant['room_id'])
    ? (int) $tenant['room_id']
    : null;


/* =========================================================
   SUBMIT MAINTENANCE REQUEST
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['submit_request'])) {

    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';

    $allowedCategories = [
        'Plumbing',
        'Electrical',
        'Air Conditioning',
        'Furniture',
        'Bathroom',
        'Other'
    ];

    if (!hash_equals($_SESSION['tenant_maintenance_csrf'], $postedToken)) {
        $errorMessage = 'Your session could not be verified. Refresh the page and try again.';
    } elseif (empty($tenant['branch_id']) || empty($roomId)) {
        $errorMessage = 'A branch room must be assigned before submitting a maintenance request.';
    } elseif ($category === '') {

        $errorMessage = 'Please select a problem type.';

    } elseif (!in_array($category, $allowedCategories, true)) {

        $errorMessage = 'Please select a valid problem type.';

    } elseif ($description === '') {

        $errorMessage = 'Please describe the problem.';

    } elseif (strlen($description) > 2000) {

        $errorMessage = 'Description must not exceed 2000 characters.';

    } else {

        /*
         * The tenant ID comes from the logged-in session.
         * The tenant cannot choose another tenant ID.
         *
         * The room ID also comes from the tenant record.
         */
        $stmt = $conn->prepare("
            INSERT INTO maintenance_requests (
                branch_id,
                tenant_id,
                room_id,
                category,
                description,
                status
            )
            VALUES (?, ?, ?, ?, ?, 'Pending')
        ");

        $stmt->bind_param(
            'iiiss',
            $tenantBranchId,
            $tenantId,
            $roomId,
            $category,
            $description
        );

        if ($stmt->execute()) {

            $successMessage = 'Maintenance request submitted successfully.';

        } else {

            $errorMessage = 'Unable to submit your maintenance request. Please try again.';
        }

        $stmt->close();
    }
}


/* =========================================================
   VIEW REQUEST DETAILS
   ========================================================= */

if (isset($_GET['request_id'])) {

    $requestId = (int) $_GET['request_id'];

    if ($requestId > 0) {

        /*
         * IMPORTANT:
         * request_id alone is NOT enough.
         *
         * We also check tenant_id so a tenant cannot
         * manually change the URL and view another
         * tenant's maintenance request.
         */
        $stmt = $conn->prepare("
            SELECT
                id,
                category,
                description,
                status,
                created_at,
                updated_at
            FROM maintenance_requests
            WHERE id = ?
              AND tenant_id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            'ii',
            $requestId,
            $tenantId
        );

        $stmt->execute();

        $selectedRequest = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$selectedRequest) {
            $errorMessage = 'Maintenance request not found.';
        }
    }
}


/* =========================================================
   GET TENANT'S MAINTENANCE REQUESTS
   ========================================================= */

$requests = [];

$stmt = $conn->prepare("
    SELECT
        id,
        category,
        description,
        status,
        created_at
    FROM maintenance_requests
    WHERE tenant_id = ?
    ORDER BY created_at DESC
");

$stmt->bind_param('i', $tenantId);
$stmt->execute();

$requests = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();


/* =========================================================
   PAGE
   ========================================================= */

include __DIR__ . '/includes/tenant_header.php';

?>

<style>

/* =========================================================
   MAINTENANCE PAGE
   ========================================================= */

.maintenance-page {
    min-height: calc(100vh - 80px);
    background: #edf9fc;
    padding: 40px 24px 60px;
}

.maintenance-container {
    max-width: 1050px;
    margin: 0 auto;
}


/* =========================================================
   PAGE HEADER
   ========================================================= */

/* =========================================================
   PAGE HEADER
   ========================================================= */

.maintenance-header {
    background: #0d5b50;
    border: 1px solid #236b67;  
    border-radius: 16px;
    border-radius: 14px;
    padding: 28px 30px;
    margin-bottom: 28px;
    box-shadow: 0 3px 12px rgba(13, 28, 30, 0.04);

}

.maintenance-header h1 {
    margin: 0 0 7px;
    color: #ffffff;
    font-family: 'Playfair Display', serif;
    font-size: 34px;
    font-weight: 700;
}

.maintenance-header p {
    margin: 0;
    color: #dff3f7;
    font-family: 'Inter', sans-serif;
    font-size: 15px;
}


/* =========================================================
   ALERTS
   ========================================================= */

.maintenance-alert {
    padding: 13px 16px;
    border-radius: 9px;
    margin-bottom: 20px;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
}

.maintenance-alert.success {
    background: #e1f7ef;
    border: 1px solid #b9e7d6;
    color: #236b67;
}

.maintenance-alert.error {
    background: #fff0f0;
    border: 1px solid #efcaca;
    color: #ba1a1a;
}


/* =========================================================
   SECTION
   ========================================================= */

.maintenance-section {
    margin-bottom: 28px;
}

.maintenance-section-title {
    margin: 0 0 14px;
    color: #0d1c1e;
    font-family: 'Playfair Display', serif;
    font-size: 23px;
}


/* =========================================================
   CARD
   ========================================================= */

.maintenance-card {
    background: #ffffff;
    border: 1px solid #d7e6ea;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 3px 12px rgba(13, 28, 30, 0.04);
}


/* =========================================================
   FORM
   ========================================================= */

.maintenance-form {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.form-group label {
    color: #236b67;
    font-family: 'Inter', sans-serif;
    font-size: 13px;
    font-weight: 600;
}

.form-input,
.form-select,
.form-textarea {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid #d7e6ea;
    border-radius: 8px;
    padding: 11px 12px;
    background: #ffffff;
    color: #0d1c1e;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    outline: none;
}

.form-input:focus,
.form-select:focus,
.form-textarea:focus {
    border-color: #22b486;
    box-shadow: 0 0 0 3px rgba(34, 180, 134, 0.08);
}

.form-textarea {
    min-height: 120px;
    resize: vertical;
}

.form-help {
    color: #6a7c7f;
    font-family: 'Inter', sans-serif;
    font-size: 12px;
}

.maintenance-submit {
    width: fit-content;
    border: none;
    border-radius: 8px;
    padding: 11px 18px;
    background: #22b486;
    color: #ffffff;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}

.maintenance-submit:hover {
    background: #1aa27a;
}


/* =========================================================
   REQUEST LIST
   ========================================================= */

.request-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.request-row {
    border: 1px solid #d7e6ea;
    border-radius: 10px;
    padding: 17px 18px;
    background: #ffffff;
}

.request-row-main {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 15px;
    align-items: start;
}

.request-category {
    margin: 0 0 6px;
    color: #0d1c1e;
    font-family: 'Inter', sans-serif;
    font-size: 15px;
    font-weight: 700;
}

.request-description {
    margin: 0 0 8px;
    color: #4f6265;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    line-height: 1.5;
}

.request-date {
    color: #6a7c7f;
    font-family: 'Inter', sans-serif;
    font-size: 12px;
}

.request-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 10px;
    border-radius: 20px;
    font-family: 'Inter', sans-serif;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
}


/* Status colors */

.status-pending {
    background: #fff2d6;
    color: #9a6700;
}

.status-progress {
    background: #dff3f7;
    color: #236b67;
}

.status-resolved {
    background: #e1f7ef;
    color: #237a5a;
}

.status-closed {
    background: #eef1f2;
    color: #5d6b6e;
}

.status-default {
    background: #eef1f2;
    color: #5d6b6e;
}


/* View Details */

.request-actions {
    margin-top: 13px;
}

.view-details {
    color: #236b67;
    font-family: 'Inter', sans-serif;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
}

.view-details:hover {
    color: #1aa27a;
    text-decoration: underline;
}


/* Empty */

.empty-requests {
    text-align: center;
    padding: 30px 20px;
    color: #6a7c7f;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
}


/* =========================================================
   DETAILS
   ========================================================= */

.details-card {
    background: #ffffff;
    border: 1px solid #d7e6ea;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 3px 12px rgba(13, 28, 30, 0.04);
}

.details-list {
    display: flex;
    flex-direction: column;
}

.details-item {
    padding: 15px 0;
    border-bottom: 1px solid #edf1f2;
}

.details-item:first-child {
    padding-top: 0;
}

.details-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.details-label {
    margin-bottom: 5px;
    color: #236b67;
    font-family: 'Inter', sans-serif;
    font-size: 12px;
    font-weight: 600;
}

.details-value {
    color: #0d1c1e;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    line-height: 1.6;
}

.details-status {
    display: inline-flex;
}

.back-link {
    display: inline-block;
    margin-top: 20px;
    color: #236b67;
    font-family: 'Inter', sans-serif;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
}

.back-link:hover {
    color: #1aa27a;
    text-decoration: underline;
}


/* =========================================================
   MOBILE
   ========================================================= */

@media (max-width: 650px) {

    .maintenance-page {
        padding: 30px 16px 45px;
    }

    .maintenance-header h1 {
        font-size: 30px;
    }

    .maintenance-card,
    .details-card {
        padding: 20px;
    }

    .request-row-main {
        grid-template-columns: 1fr;
        gap: 8px;
    }

    .request-status {
        width: fit-content;
    }

    .maintenance-submit {
        width: 100%;
    }
}

</style>


<main class="maintenance-page">

    <div class="maintenance-container">


        <!-- =================================================
             PAGE HEADER
             ================================================= -->

     <div class="maintenance-header">

    <h1>Maintenance</h1>

    <p>
        Report a problem or request a repair.
    </p>

</div>


        <!-- =================================================
             ALERTS
             ================================================= -->

        <?php if ($successMessage): ?>

            <div class="maintenance-alert success">
                <?= e($successMessage) ?>
            </div>

        <?php endif; ?>


        <?php if ($errorMessage): ?>

            <div class="maintenance-alert error">
                <?= e($errorMessage) ?>
            </div>

        <?php endif; ?>


        <?php if ($selectedRequest): ?>


            <!-- =============================================
                 REQUEST DETAILS
                 ============================================= -->

            <section class="maintenance-section">

                <h2 class="maintenance-section-title">
                    Maintenance Request Details
                </h2>


                <div class="details-card">

                    <div class="details-list">


                        <!-- Problem Type -->

                        <div class="details-item">

                            <div class="details-label">
                                Problem Type
                            </div>

                            <div class="details-value">
                                <?= e($selectedRequest['category']) ?>
                            </div>

                        </div>


                        <!-- Description -->

                        <div class="details-item">

                            <div class="details-label">
                                Description
                            </div>

                            <div class="details-value">
                                <?= nl2br(e($selectedRequest['description'])) ?>
                            </div>

                        </div>


                        <!-- Date Submitted -->

                        <div class="details-item">

                            <div class="details-label">
                                Date Submitted
                            </div>

                            <div class="details-value">
                                <?= e(
                                    date(
                                        'F j, Y g:i A',
                                        strtotime($selectedRequest['created_at'])
                                    )
                                ) ?>
                            </div>

                        </div>


                        <!-- Status -->

                        <div class="details-item">

                            <div class="details-label">
                                Status
                            </div>

                            <div class="details-value">

                                <?php
                                $detailStatus = $selectedRequest['status'];

                                $detailStatusClass = 'status-default';

                                if ($detailStatus === 'Pending') {
                                    $detailStatusClass = 'status-pending';
                                } elseif ($detailStatus === 'In Progress') {
                                    $detailStatusClass = 'status-progress';
                                } elseif ($detailStatus === 'Resolved') {
                                    $detailStatusClass = 'status-resolved';
                                } elseif ($detailStatus === 'Closed') {
                                    $detailStatusClass = 'status-closed';
                                }
                                ?>

                                <span class="request-status <?= e($detailStatusClass) ?>">
                                    <?= e($detailStatus) ?>
                                </span>

                            </div>

                        </div>


                        <!-- Updated -->

                        <div class="details-item">

                            <div class="details-label">
                                Last Updated
                            </div>

                            <div class="details-value">
                                <?= e(
                                    date(
                                        'F j, Y g:i A',
                                        strtotime($selectedRequest['updated_at'])
                                    )
                                ) ?>
                            </div>

                        </div>


                        <!-- Database Limitation Notice -->

                        <div class="details-item">

                            <div class="details-label">
                                Admin Response
                            </div>

                            <div class="details-value">
                                No response yet.
                            </div>

                        </div>

                    </div>


                    <a
                        href="tenant_maintenance.php"
                        class="back-link"
                    >
                        ← Back to My Requests
                    </a>

                </div>

            </section>


        <?php else: ?>


            <!-- =============================================
                 SUBMIT REQUEST
                 ============================================= -->

            <section class="maintenance-section">

                <h2 class="maintenance-section-title">
                    Submit a Maintenance Request
                </h2>


                <div class="maintenance-card">

                    <form
                        method="POST"
                        class="maintenance-form"
                    >
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['tenant_maintenance_csrf']); ?>">


                        <!-- Problem Type -->

                        <div class="form-group">

                            <label for="category">
                                Problem Type
                            </label>

                            <select
                                name="category"
                                id="category"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Select a problem type
                                </option>

                                <option value="Plumbing">
                                    Plumbing
                                </option>

                                <option value="Electrical">
                                    Electrical
                                </option>

                                <option value="Air Conditioning">
                                    Air Conditioning
                                </option>

                                <option value="Furniture">
                                    Furniture
                                </option>

                                <option value="Bathroom">
                                    Bathroom
                                </option>

                                <option value="Other">
                                    Other
                                </option>

                            </select>

                        </div>


                        <!-- Description -->

                        <div class="form-group">

                            <label for="description">
                                Description
                            </label>

                            <textarea
                                name="description"
                                id="description"
                                class="form-textarea"
                                placeholder="Describe the problem..."
                                maxlength="2000"
                                required
                            ></textarea>

                            <div class="form-help">
                                Please provide enough details to help the administrator understand the problem.
                            </div>

                        </div>


                        <!-- Submit -->

                        <button
                            type="submit"
                            name="submit_request"
                            class="maintenance-submit"
                        >
                            Submit Request
                        </button>

                    </form>

                </div>

            </section>


            <!-- =============================================
                 MY REQUESTS
                 ============================================= -->

            <section class="maintenance-section">

                <h2 class="maintenance-section-title">
                    My Maintenance Requests
                </h2>


                <?php if (empty($requests)): ?>

                    <div class="maintenance-card">

                        <div class="empty-requests">
                            No maintenance requests yet.
                        </div>

                    </div>

                <?php else: ?>

                    <div class="request-list">

                        <?php foreach ($requests as $request): ?>

                            <?php

                            $status = $request['status'];

                            $statusClass = 'status-default';

                            if ($status === 'Pending') {
                                $statusClass = 'status-pending';
                            } elseif ($status === 'In Progress') {
                                $statusClass = 'status-progress';
                            } elseif ($status === 'Resolved') {
                                $statusClass = 'status-resolved';
                            } elseif ($status === 'Closed') {
                                $statusClass = 'status-closed';
                            }

                            ?>

                            <div class="request-row">

                                <div class="request-row-main">


                                    <!-- Request Information -->

                                    <div>

                                        <p class="request-category">
                                            <?= e($request['category']) ?>
                                        </p>

                                        <p class="request-description">

                                            <?php
                                            $description = trim(
                                                $request['description']
                                            );

                                            if (strlen($description) > 140) {
                                                $description =
                                                    substr($description, 0, 140)
                                                    . '...';
                                            }

                                            echo e($description);
                                            ?>

                                        </p>

                                        <div class="request-date">

                                            Submitted:
                                            <?= e(
                                                date(
                                                    'F j, Y',
                                                    strtotime($request['created_at'])
                                                )
                                            ) ?>

                                        </div>

                                    </div>


                                    <!-- Status -->

                                    <span class="request-status <?= e($statusClass) ?>">
                                        <?= e($status) ?>
                                    </span>

                                </div>


                                <!-- View Details -->

                                <div class="request-actions">

                                    <a
                                        href="tenant_maintenance.php?request_id=<?= (int) $request['id'] ?>"
                                        class="view-details"
                                    >
                                        View Details
                                    </a>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>


        <?php endif; ?>


    </div>

</main>


<?php include __DIR__ . '/includes/footer.php'; ?>