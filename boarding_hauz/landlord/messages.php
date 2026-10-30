<?php

require_once __DIR__ . '/../auth.php';
requireRole('landlord');

$admin_title = 'Messages';

$branchId = requireSelectedBranch();
$adminId = currentBranchLandlordId();

$selectedTenantIdValue = is_scalar($_GET['tenant_id'] ?? null)
    ? (string) $_GET['tenant_id']
    : '';

$selectedTenantId = ctype_digit($selectedTenantIdValue)
    ? (int) $selectedTenantIdValue
    : 0;

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['admin_messages_csrf'])) {
    $_SESSION['admin_messages_csrf'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| HANDLE POST REQUESTS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = is_string($_POST['action'] ?? null)
        ? $_POST['action']
        : '';

    $postedToken = is_string($_POST['csrf_token'] ?? null)
        ? $_POST['csrf_token']
        : '';

    /*
    |--------------------------------------------------------------------------
    | CSRF VALIDATION
    |--------------------------------------------------------------------------
    */

    if (
        !hash_equals(
            $_SESSION['admin_messages_csrf'],
            $postedToken
        )
    ) {

        $error = 'Your session could not be verified. Refresh the page and try again.';

    } elseif ($action === 'send_tenant_message') {

        /*
        |--------------------------------------------------------------------------
        | SEND MESSAGE TO TENANT
        |--------------------------------------------------------------------------
        */

        $receiverIdValue = is_scalar($_POST['receiver_id'] ?? null)
            ? (string) $_POST['receiver_id']
            : '';

        $receiverId = ctype_digit($receiverIdValue)
            ? (int) $receiverIdValue
            : 0;

        $message = is_string($_POST['message'] ?? null)
            ? trim($_POST['message'])
            : '';

        $selectedTenantId = $receiverId;

        /*
        |--------------------------------------------------------------------------
        | VALIDATION
        |--------------------------------------------------------------------------
        */

        if ($receiverId <= 0) {

            $error = 'Please select a tenant.';

        } elseif ($message === '') {

            $error = 'Please enter a message.';

        } elseif (mb_strlen($message) > 2000) {

            $error = 'Message must not exceed 2000 characters.';

        } else {

            try {

                /*
                |--------------------------------------------------------------------------
                | VERIFY TENANT
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    SELECT u.id
                    FROM users u
                    INNER JOIN tenants t ON t.user_id = u.id
                    WHERE u.id = ?
                      AND u.role = 'tenant'
                      AND t.branch_id = ?
                    LIMIT 1
                ");

                $stmt->bind_param('ii', $receiverId, $branchId);

                $stmt->execute();

                $tenantUser = $stmt
                    ->get_result()
                    ->fetch_assoc();

                $stmt->close();

                if (!$tenantUser) {

                    $error = 'Invalid tenant selected.';

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | INSERT MESSAGE
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $conn->prepare("
                        INSERT INTO messages
                            (sender_id, receiver_id, message, is_read)
                        VALUES
                            (?, ?, ?, 0)
                    ");

                    $stmt->bind_param(
                        'iis',
                        $adminId,
                        $receiverId,
                        $message
                    );

                    $stmt->execute();
                    $stmt->close();

                    /*
                    |--------------------------------------------------------------------------
                    | PRG REDIRECT
                    |--------------------------------------------------------------------------
                    */

                    header(
                        'Location: /boarding_hauz/landlord/messages.php?tenant_id='
                        . $receiverId
                        . '&sent=1#tenant-messages'
                    );

                    exit;
                }

            } catch (mysqli_sql_exception $exception) {

                error_log(
                    'Admin tenant message failed: '
                    . $exception->getMessage()
                );

                $error = 'Unable to send the message. Please try again.';
            }
        }

    } else {

        $error = 'Invalid message action.';
    }
}

/*
|--------------------------------------------------------------------------
| SUCCESS MESSAGE
|--------------------------------------------------------------------------
*/

if (isset($_GET['sent'])) {

    $success = 'Message sent successfully.';
}

/*
|--------------------------------------------------------------------------
| LOAD TENANTS
|--------------------------------------------------------------------------
*/

$tenants = [];

try {

    $stmt = $conn->prepare("
        SELECT
            u.id AS user_id,
            u.username,
            u.email,
            t.full_name,
            t.room_id,
            r.room_number,

            (
                SELECT COUNT(*)
                FROM messages m
                WHERE m.sender_id = u.id
                  AND m.receiver_id = ?
                  AND m.is_read = 0
            ) AS unread_count

        FROM users u

        INNER JOIN tenants t
            ON t.user_id = u.id

        LEFT JOIN rooms r
            ON r.id = t.room_id
        WHERE u.role = 'tenant'
          AND t.branch_id = ?
        ORDER BY t.full_name ASC
    ");

    $stmt->bind_param('ii', $adminId, $branchId);

    $stmt->execute();

    $tenants = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

} catch (mysqli_sql_exception $exception) {

    error_log(
        'Admin tenant conversation list failed: '
        . $exception->getMessage()
    );

    $error = 'Tenant conversations could not be loaded.';
}

/*
|--------------------------------------------------------------------------
| DEFAULT SELECTED TENANT
|--------------------------------------------------------------------------
*/

if ($selectedTenantId === 0 && !empty($tenants)) {

    $selectedTenantId = (int) $tenants[0]['user_id'];
}

/*
|--------------------------------------------------------------------------
| FIND SELECTED TENANT
|--------------------------------------------------------------------------
*/

$selectedTenant = null;

foreach ($tenants as $tenant) {

    if ((int) $tenant['user_id'] === $selectedTenantId) {

        $selectedTenant = $tenant;

        break;
    }
}

/*
|--------------------------------------------------------------------------
| LOAD CONVERSATION
|--------------------------------------------------------------------------
*/

$conversation = [];

if ($selectedTenant) {

    try {

        /*
        |--------------------------------------------------------------------------
        | MARK TENANT MESSAGES AS READ
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            UPDATE messages
            SET is_read = 1

            WHERE sender_id = ?
              AND receiver_id = ?
              AND is_read = 0
        ");

        $stmt->bind_param(
            'ii',
            $selectedTenantId,
            $adminId
        );

        $stmt->execute();
        $stmt->close();

        /*
        |--------------------------------------------------------------------------
        | GET CONVERSATION
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                id,
                sender_id,
                receiver_id,
                message,
                created_at,
                is_read

            FROM messages

            WHERE
                (
                    sender_id = ?
                    AND receiver_id = ?
                )

                OR

                (
                    sender_id = ?
                    AND receiver_id = ?
                )

            ORDER BY
                created_at ASC,
                id ASC
        ");

        $stmt->bind_param(
            'iiii',
            $adminId,
            $selectedTenantId,
            $selectedTenantId,
            $adminId
        );

        $stmt->execute();

        $conversation = $stmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);

        $stmt->close();

    } catch (mysqli_sql_exception $exception) {

        error_log(
            'Admin tenant conversation load failed: '
            . $exception->getMessage()
        );

        $error = 'The selected tenant conversation could not be loaded.';
    }
}

/*
|--------------------------------------------------------------------------
| ADMIN HEADER
|--------------------------------------------------------------------------
*/

include __DIR__ . '/../admin/admin_header.php';

?>

<style>

/* =========================================================
   MESSAGES PAGE
   ========================================================= */

.messages-page {
    max-width: 1100px;
    margin: 0 auto;
    padding: 32px 22px 50px;
}


/* =========================================================
   PAGE HEADER
   ========================================================= */

.page-header {
    background: #0d5b50;
    border: 1px solid #236b67;
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 22px;
}

.page-header h1 {
    margin: 0 0 5px;
    color: #ffffff;
    font-family: 'Playfair Display', serif;
    font-size: 31px;
}

.page-header p {
    margin: 0;
    color: #dff3f7;
    font-size: 13px;
}


/* =========================================================
   ALERTS
   ========================================================= */

.message-alert {
    margin-bottom: 15px;
    padding: 11px 14px;
    border-radius: 8px;
    font-size: 12px;
}

.message-alert.success {
    background: #dff4e9;
    color: #18734f;
}

.message-alert.error {
    background: #fde5e5;
    color: #ba1a1a;
}


/* =========================================================
   MAIN MESSAGE LAYOUT
   ========================================================= */

.messages-layout {
    display: grid;
    grid-template-columns: 280px 1fr;

    min-height: 560px;

    background: #ffffff;

    border: 1px solid #d7e6ea;
    border-radius: 14px;

    overflow: hidden;
}


/* =========================================================
   TENANT LIST
   ========================================================= */

.tenant-list {
    border-right: 1px solid #d7e6ea;
    background: #f8fcfd;
}

.tenant-list-header {
    padding: 18px;
    border-bottom: 1px solid #d7e6ea;
}

.tenant-list-header h2 {
    margin: 0;
    color: #0d1c1e;
    font-size: 16px;
}

.tenant-search {
    width: 100%;
    box-sizing: border-box;
    margin-top: 12px;
    padding: 9px 10px;
    border: 1px solid #d7e6ea;
    border-radius: 7px;
    background: #fff;
    color: #0d1c1e;
    font: 12px 'Inter', sans-serif;
}

.tenant-search:focus {
    outline: none;
    border-color: #22b486;
    box-shadow: 0 0 0 3px rgba(34, 180, 134, 0.10);
}

.visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.tenant-search-empty {
    display: none;
    padding: 14px 17px;
    color: #667b7e;
    font-size: 12px;
}

.tenant-item {
    display: block;

    padding: 15px 17px;

    border-bottom: 1px solid #d7e6ea;

    color: #0d1c1e;
    text-decoration: none;
}

.tenant-item[hidden] {
    display: none;
}

.tenant-item:hover {
    background: #edf9fc;
}

.tenant-item.active {
    background: #dff3f7;

    border-left: 4px solid #22b486;

    padding-left: 13px;
}

.tenant-name {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 8px;

    font-size: 13px;
    font-weight: 700;
}

.tenant-room {
    margin-top: 4px;

    color: #667b7e;

    font-size: 11px;
}

.unread-badge {
    min-width: 20px;

    padding: 2px 6px;

    border-radius: 20px;

    background: #22b486;
    color: #ffffff;

    text-align: center;

    font-size: 10px;
    font-weight: 700;
}


/* =========================================================
   CHAT AREA
   ========================================================= */

.chat-area {
    display: flex;
    flex-direction: column;

    min-width: 0;
}


/* =========================================================
   CHAT HEADER
   ========================================================= */

.chat-header {
    padding: 18px 20px;

    border-bottom: 1px solid #d7e6ea;

    background: #ffffff;
}

.chat-header strong,
.chat-header span {
    display: block;
}

.chat-header strong {
    color: #0d1c1e;
    font-size: 15px;
}

.chat-header span {
    margin-top: 3px;

    color: #667b7e;

    font-size: 11px;
}


/* =========================================================
   CHAT MESSAGES
   ========================================================= */

.chat-messages {
    flex: 1;

    min-height: 360px;
    max-height: 600px;

    overflow-y: auto;

    padding: 22px;

    background: #f8fcfd;
}

.message-row {
    display: flex;

    margin-bottom: 14px;
}

.message-row.admin {
    justify-content: flex-end;
}

.message-bubble {
    max-width: 70%;

    padding: 10px 13px;

    border-radius: 12px;

    font-size: 13px;
    line-height: 1.5;

    overflow-wrap: anywhere;
}


/* Tenant message */

.message-row.tenant .message-bubble {
    background: #dff3f7;
    color: #0d1c1e;

    border-bottom-left-radius: 4px;
}


/* Admin message */

.message-row.admin .message-bubble {
    background: #22b486;
    color: #ffffff;

    border-bottom-right-radius: 4px;
}


/* Message time */

.message-time {
    margin-top: 4px;

    color: #7b8d90;

    font-size: 9px;
}

.message-row.admin .message-time {
    text-align: right;
}


/* =========================================================
   EMPTY CHAT
   ========================================================= */

.empty-chat {
    flex: 1;

    display: grid;
    place-items: center;

    padding: 30px;

    text-align: center;

    color: #667b7e;

    font-size: 13px;
}


/* =========================================================
   MESSAGE FORM
   ========================================================= */

.message-form {
    padding: 15px 18px;

    border-top: 1px solid #d7e6ea;

    background: #ffffff;
}

.message-form textarea {
    width: 100%;

    min-height: 70px;

    box-sizing: border-box;

    resize: vertical;

    padding: 11px 12px;

    border: 1px solid #d7e6ea;
    border-radius: 9px;

    font: 13px 'Inter', sans-serif;
}

.message-form textarea:focus {
    outline: none;

    border-color: #22b486;

    box-shadow: 0 0 0 3px rgba(34, 180, 134, 0.10);
}

.message-form-bottom {
    display: flex;

    align-items: center;
    justify-content: space-between;

    gap: 10px;

    margin-top: 9px;
}

.message-note {
    color: #7b8d90;

    font-size: 10px;
}

.send-button {
    border: 0;

    padding: 10px 18px;

    border-radius: 8px;

    background: #22b486;
    color: #ffffff;

    font: 700 12px 'Inter', sans-serif;

    cursor: pointer;
}

.send-button:hover {
    background: #1aa27a;
}


/* =========================================================
   MOBILE
   ========================================================= */

@media (max-width: 800px) {

    .messages-layout {
        grid-template-columns: 1fr;
    }

    .tenant-list {
        border-right: 0;

        border-bottom: 1px solid #d7e6ea;

        max-height: 230px;

        overflow-y: auto;
    }

}

@media (max-width: 600px) {

    .messages-page {
        padding: 22px 14px 40px;
    }

    .page-header {
        padding: 20px;
    }

    .page-header h1 {
        font-size: 26px;
    }

    .message-bubble {
        max-width: 85%;
    }

}

</style>


<main class="messages-page">

    <!-- =====================================================
         PAGE HEADER
         ===================================================== -->

    <section class="page-header">

        <h1>Messages</h1>

        <p>
            Communicate directly with Boarding Hauz tenants.
        </p>

    </section>


    <!-- =====================================================
         SUCCESS / ERROR MESSAGES
         ===================================================== -->

    <?php if ($success !== ''): ?>

        <div class="message-alert success">
            <?= e($success); ?>
        </div>

    <?php endif; ?>


    <?php if ($error !== ''): ?>

        <div class="message-alert error">
            <?= e($error); ?>
        </div>

    <?php endif; ?>


    <!-- =====================================================
         TENANT MESSAGES
         ===================================================== -->

    <section class="messages-layout" id="tenant-messages">


        <!-- =================================================
             TENANT LIST
             ================================================= -->

        <aside class="tenant-list">

            <div class="tenant-list-header">

                <h2>Tenant Messages</h2>
                <label class="visually-hidden" for="tenantSearch">Search tenant name or email</label>
                <input
                    id="tenantSearch"
                    class="tenant-search"
                    type="search"
                    placeholder="Search tenant name or email..."
                    autocomplete="off"
                >

            </div>


            <?php if (!$tenants): ?>

                <div class="empty-chat">

                    No tenant conversations are available yet.

                </div>

            <?php else: ?>

                <?php foreach ($tenants as $tenant): ?>

                    <a
                        class="tenant-item <?= (int) $tenant['user_id'] === $selectedTenantId ? 'active' : ''; ?>"
                        data-search="<?= e(($tenant['full_name'] ?: $tenant['username']) . ' ' . $tenant['email']); ?>"
                        href="/boarding_hauz/landlord/messages.php?tenant_id=<?= (int) $tenant['user_id']; ?>#tenant-messages"
                    >

                        <div class="tenant-name">

                            <span>
                                <?= e(
                                    $tenant['full_name']
                                    ?: $tenant['username']
                                ); ?>
                            </span>


                            <?php if ((int) $tenant['unread_count'] > 0): ?>

                                <span class="unread-badge">
                                    <?= (int) $tenant['unread_count']; ?>
                                </span>

                            <?php endif; ?>

                        </div>


                        <div class="tenant-room">

                            <?php if (!empty($tenant['room_number'])): ?>

                                Room <?= e($tenant['room_number']); ?>

                            <?php else: ?>

                                No room assigned

                            <?php endif; ?>

                        </div>

                    </a>

                <?php endforeach; ?>

                <div class="tenant-search-empty" id="tenantSearchEmpty" role="status">
                    No tenants found.
                </div>

            <?php endif; ?>

        </aside>


        <!-- =================================================
             CHAT AREA
             ================================================= -->

        <section class="chat-area">


            <?php if ($selectedTenant): ?>


                <!-- =============================================
                     CHAT HEADER
                     ============================================= -->

                <header class="chat-header">

                    <strong>
                        <?= e(
                            $selectedTenant['full_name']
                            ?: $selectedTenant['username']
                        ); ?>
                    </strong>


                    <span>

                        <?php if (!empty($selectedTenant['room_number'])): ?>

                            Room <?= e($selectedTenant['room_number']); ?>

                        <?php else: ?>

                            No room assigned

                        <?php endif; ?>

                    </span>

                </header>


                <!-- =============================================
                     CONVERSATION
                     ============================================= -->

                <div
                    class="chat-messages"
                    id="chatMessages"
                >

                    <?php if (!$conversation): ?>

                        <div class="empty-chat">

                            No messages yet.
                            Send a message to contact this tenant.

                        </div>

                    <?php else: ?>


                        <?php foreach ($conversation as $message): ?>

                            <?php

                            $isAdminMessage =
                                (int) $message['sender_id'] === $adminId;

                            $messageTimestamp =
                                strtotime($message['created_at']);

                            ?>


                            <div
                                class="message-row <?= $isAdminMessage ? 'admin' : 'tenant'; ?>"
                            >

                                <div>

                                    <div class="message-bubble">

                                        <?= nl2br(
                                            e($message['message'])
                                        ); ?>

                                    </div>


                                    <div class="message-time">

                                        <?php if ($messageTimestamp): ?>

                                            <?= e(
                                                date(
                                                    'M d, Y g:i A',
                                                    $messageTimestamp
                                                )
                                            ); ?>

                                        <?php else: ?>

                                            <?= e(
                                                $message['created_at']
                                            ); ?>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>


                    <?php endif; ?>

                </div>


                <!-- =============================================
                     SEND MESSAGE
                     ============================================= -->

                <form
                    method="post"
                    class="message-form"
                    action="/boarding_hauz/landlord/messages.php?tenant_id=<?= (int) $selectedTenantId; ?>#tenant-messages"
                >

                    <input
                        type="hidden"
                        name="action"
                        value="send_tenant_message"
                    >

                    <input
                        type="hidden"
                        name="receiver_id"
                        value="<?= (int) $selectedTenantId; ?>"
                    >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION['admin_messages_csrf']); ?>"
                    >


                    <textarea
                        name="message"
                        maxlength="2000"
                        placeholder="Type a message..."
                        required
                    ></textarea>


                    <div class="message-form-bottom">

                        <span class="message-note">
                            Maximum 2000 characters
                        </span>


                        <button
                            type="submit"
                            class="send-button"
                        >
                            Send Message
                        </button>

                    </div>

                </form>


            <?php else: ?>


                <div class="empty-chat">

                    <?php if (!$tenants): ?>

                        No tenants are available yet.

                    <?php else: ?>

                        Select a tenant to view the conversation.

                    <?php endif; ?>

                </div>


            <?php endif; ?>


        </section>

    </section>

</main>


<script>

/*
|--------------------------------------------------------------------------
| Automatically scroll chat to the latest message
|--------------------------------------------------------------------------
*/

const chatMessages =
    document.getElementById('chatMessages');

if (chatMessages) {

    chatMessages.scrollTop =
        chatMessages.scrollHeight;

}

const tenantSearch =
    document.getElementById('tenantSearch');
const tenantSearchEmpty =
    document.getElementById('tenantSearchEmpty');
const tenantItems =
    Array.from(document.querySelectorAll('.tenant-item'));

if (tenantSearch) {
    tenantSearch.addEventListener('input', function () {
        const query = tenantSearch.value.trim().toLocaleLowerCase();
        let visibleCount = 0;

        tenantItems.forEach(function (tenantItem) {
            const searchableText = (tenantItem.dataset.search || '').toLocaleLowerCase();
            const matches = searchableText.includes(query);
            tenantItem.hidden = !matches;
            if (matches) {
                visibleCount += 1;
            }
        });

        if (tenantSearchEmpty) {
            tenantSearchEmpty.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    });
}

</script>

</body>
</html>